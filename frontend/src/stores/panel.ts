import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import http from '@/lib/http'
import realtimeService, { type EstadoConexion } from '@/services/realtime'
import { useAmbienteStore } from '@/stores/ambiente'
import type { EstiloRuta, LatLngLike } from '@/services/maps/types'

/**
 * Estado del `/panel` (spec tenant/027).
 *
 * Es la única fuente de verdad de las dos listas laterales y del mapa. Antes cada componente tenía
 * la suya y la recargaba entera con cada evento del canal: `ServiciosEnTurno` paginaba el historial
 * completo de `GET /pedidos`, `ConductoresActivos` y `MapaConductores` pedían por separado
 * `GET /conductores/activos`, y los tres a la vez contra un limitador de 20 peticiones por minuto.
 * El resultado era el 429 que dejaba los dos paneles en "No se pudo cargar", más un parpadeo en
 * cada evento porque la lista se vaciaba para volver a llenarse.
 *
 * Aquí no se recarga: se aplica. Cada evento trae la fila ya resuelta (spec tenant/027, §5) y se
 * escribe encima de la que está en memoria. Solo se vuelve a pedir la lista en los cuatro casos de
 * RN-11 —abrir, reconectar, volver de segundo plano y evento sobre una fila desconocida—, y esas
 * peticiones se agrupan (RN-12) y fusionan por id sin vaciar nada (RN-13).
 */

export type EstadoPedido =
  | 'PENDIENTE'
  | 'PUBLICADO'
  | 'TOMADO'
  | 'ARRIBADO'
  | 'EN_CAMINO'
  | 'ARRIBADO_A_ENTREGA'
  | 'ENTREGADO'
  | 'CANCELADO'
  | 'RECHAZADO'

const ESTADOS_FINALES: EstadoPedido[] = ['ENTREGADO', 'CANCELADO', 'RECHAZADO']

export interface ViajeEnTurno {
  id_pedido: number
  numero_pedido: string
  direccion_recogida: string
  direccion_entrega: string
  latitud_recogida?: number | string | null
  longitud_recogida?: number | string | null
  latitud_entrega?: number | string | null
  longitud_entrega?: number | string | null
  estado: EstadoPedido
  lo_antes_posible: boolean
  fecha_servicio: string | null
  hora_desde: string | null
  nombre_solicitante: string | null
  telefono_solicitante: string | null
  importe_envio: string | number | null
  id_conductor: number | null
  conductor_nombre: string | null
  ambiente?: string | null
}

/** Qué línea toca dibujar, ya decidido por el servidor (spec tenant/026, RN-13). */
export interface Seguimiento {
  hito: 'H1' | 'H2'
  estilo: EstiloRuta
  color: string
  /** `null` en H1: el origen es la posición viva del conductor. */
  origen: LatLngLike | null
  destino: LatLngLike
}

export interface PedidoAsignado {
  id_pedido: number
  numero_pedido: string
  estado: EstadoPedido
  direccion_recogida: string
  latitud_recogida: number
  longitud_recogida: number
  direccion_entrega: string
  latitud_entrega: number
  longitud_entrega: number
  seguimiento: Seguimiento | null
}

export interface ConductorActivo {
  id_conductor: number
  nombre: string
  disponibilidad: string
  color: string
  placa: string | null
  marca: string | null
  saldo_viajes: number
  latitud: number | null
  longitud: number | null
  pedido_asignado: PedidoAsignado | null
}

interface EventoBase {
  event_id?: string
  ambiente?: string | null
}

/** RN-12: los disparos de reconciliación se agrupan en esta ventana. */
const DEBOUNCE_SINCRONIZACION_MS = 500
/** RN-14: cada cuánto se pide el estado real mientras el socket está caído. */
const SONDEO_RESPALDO_MS = 30_000
/** RN-11 (c): volver a la pestaña reconcilia solo si estuvo oculta más que esto. */
const OCULTA_PARA_RECONCILIAR_MS = 60_000
/** RN-22: cuánto dura el resaltado de la fila que acaba de cambiar. */
const RESALTADO_MS = 600
/** RN-23: cuánto se queda en pantalla un viaje entregado antes de salir. */
const SALIDA_ENTREGADO_MS = 800
/** RN-06: cuántos `event_id` se recuerdan para descartar repetidos. */
const EVENTOS_RECORDADOS = 200
/** §7: espera creciente entre reintentos cuando la reconciliación falla. */
const REINTENTOS_MS = [2_000, 4_000, 8_000, 16_000, 30_000]

export const usePanelStore = defineStore('panel', () => {
  const ambientes = useAmbienteStore()

  const viajes = ref(new Map<number, ViajeEnTurno>())
  const conductores = ref(new Map<number, ConductorActivo>())
  const conexion = ref<EstadoConexion>('conectando')
  /** RN-16: el único estado que muestra esqueleto. Se apaga en la primera carga que sale bien. */
  const primeraCarga = ref(true)
  /** RN-18: hubo un fallo de red y lo que está en pantalla puede estar viejo. */
  const desincronizado = ref(false)
  /** RN-23: viajes marcados como entregados que siguen visibles mientras se animan hacia afuera. */
  const saliendo = ref(new Set<number>())
  /** RN-22: claves (`viaje:12`, `conductor:3`) de las filas que acaban de cambiar. */
  const resaltados = ref(new Set<string>())
  /** Último conductor que se puso en línea, para que la flotilla saque su aviso (spec tenant/019). */
  const ultimaConexionDeConductor = ref<{
    id_conductor: number
    nombre: string
    en: number
  } | null>(null)

  let slug = ''
  let iniciado = false
  let temporizadorSincronizacion: ReturnType<typeof setTimeout> | null = null
  let temporizadorReintento: ReturnType<typeof setTimeout> | null = null
  let temporizadorSondeo: ReturnType<typeof setInterval> | null = null
  let sincronizacionEnVuelo: Promise<void> | null = null
  let sincronizacionPendiente = false
  let intentosFallidos = 0
  let ocultaDesde: number | null = null
  let dejarDeObservarConexion: (() => void) | null = null

  const eventosVistos = new Set<string>()
  const ordenDeEventos: string[] = []

  // ---------------------------------------------------------------------------------------------
  // Lecturas
  // ---------------------------------------------------------------------------------------------

  /**
   * El mismo orden que devuelve `GET /pedidos/en-turno` (RN-03). Se repite aquí porque un viaje que
   * llega por evento tiene que caer en su sitio sin volver a preguntarle al servidor dónde va.
   */
  const viajesOrdenados = computed<ViajeEnTurno[]>(() =>
    [...viajes.value.values()].sort((a, b) => {
      if (a.lo_antes_posible !== b.lo_antes_posible) return a.lo_antes_posible ? -1 : 1
      const hora = (a.hora_desde ?? '').localeCompare(b.hora_desde ?? '')
      return hora !== 0 ? hora : b.id_pedido - a.id_pedido
    }),
  )

  const conductoresOrdenados = computed<ConductorActivo[]>(() =>
    [...conductores.value.values()].sort((a, b) => a.nombre.localeCompare(b.nombre)),
  )

  const enLinea = computed(() => conductores.value.size)

  /** RN-19: el vacío solo se pinta cuando el servidor confirmó cero, nunca por un fallo. */
  const hayDatos = computed(() => !primeraCarga.value)

  function estaSaliendo(idPedido: number): boolean {
    return saliendo.value.has(idPedido)
  }

  function estaResaltado(clave: string): boolean {
    return resaltados.value.has(clave)
  }

  // ---------------------------------------------------------------------------------------------
  // Ciclo de vida
  // ---------------------------------------------------------------------------------------------

  function iniciar(nuevoSlug: string) {
    if (iniciado && slug === nuevoSlug) return
    if (iniciado) detener()

    // Volver al Panel del mismo tenant conserva lo que había y solo lo reconcilia: no hay razón para
    // enseñar un esqueleto de algo que ya se sabe. Cambiar de tenant sí borra todo — el store es un
    // singleton y de otro modo se vería un instante la operación de otra empresa.
    if (slug !== '' && slug !== nuevoSlug) {
      viajes.value.clear()
      conductores.value.clear()
      saliendo.value.clear()
      resaltados.value.clear()
      ultimaConexionDeConductor.value = null
      primeraCarga.value = true
      desincronizado.value = false
    }

    slug = nuevoSlug
    iniciado = true

    const canal = realtimeService.subscribe(slug)
    for (const [evento, manejador] of Object.entries(REDUCTORES)) {
      canal?.bind(evento, manejador)
    }

    dejarDeObservarConexion = realtimeService.alCambiarEstado(onCambioDeConexion)
    document.addEventListener('visibilitychange', onCambioDeVisibilidad)

    void sincronizar()
  }

  function detener() {
    if (!iniciado) return

    const canal = realtimeService.subscribe(slug)
    for (const [evento, manejador] of Object.entries(REDUCTORES)) {
      canal?.unbind(evento, manejador)
    }

    dejarDeObservarConexion?.()
    dejarDeObservarConexion = null
    document.removeEventListener('visibilitychange', onCambioDeVisibilidad)

    if (temporizadorSincronizacion !== null) clearTimeout(temporizadorSincronizacion)
    if (temporizadorReintento !== null) clearTimeout(temporizadorReintento)
    if (temporizadorSondeo !== null) clearInterval(temporizadorSondeo)
    temporizadorSincronizacion = null
    temporizadorReintento = null
    temporizadorSondeo = null

    iniciado = false
  }

  /**
   * RN-11 (b) y RN-14. Volver de una caída obliga a reconciliar una vez: mientras el socket estuvo
   * abajo pudo pasar cualquier cosa. Y mientras siga abajo corre el sondeo de respaldo, que es la
   * única petición periódica del Panel — con el socket vivo no hay ninguna.
   */
  function onCambioDeConexion(estado: EstadoConexion) {
    const veniaCaido = conexion.value === 'caido'
    conexion.value = estado

    if (estado === 'vivo') {
      if (temporizadorSondeo !== null) {
        clearInterval(temporizadorSondeo)
        temporizadorSondeo = null
      }
      if (veniaCaido) agendarSincronizacion()
      return
    }

    if (estado === 'caido' && temporizadorSondeo === null) {
      temporizadorSondeo = setInterval(() => agendarSincronizacion(), SONDEO_RESPALDO_MS)
    }
  }

  /**
   * RN-11 (c). Una pestaña en segundo plano deja de recibir eventos de forma fiable —el navegador
   * puede suspender el socket—, así que al volver se reconstruye el estado con **una** petición, no
   * con una por cada evento que se perdió.
   */
  function onCambioDeVisibilidad() {
    if (document.visibilityState === 'hidden') {
      ocultaDesde = Date.now()
      return
    }

    const estuvoOculta =
      ocultaDesde !== null && Date.now() - ocultaDesde > OCULTA_PARA_RECONCILIAR_MS
    ocultaDesde = null
    if (estuvoOculta) agendarSincronizacion()
  }

  // ---------------------------------------------------------------------------------------------
  // Reconciliación (RN-11 a RN-14)
  // ---------------------------------------------------------------------------------------------

  function agendarSincronizacion() {
    if (temporizadorSincronizacion !== null) return

    temporizadorSincronizacion = setTimeout(() => {
      temporizadorSincronizacion = null
      void sincronizar()
    }, DEBOUNCE_SINCRONIZACION_MS)
  }

  async function sincronizar(): Promise<void> {
    // Nunca dos en vuelo (RN-12): la que llega mientras hay otra corriendo se apunta para después,
    // porque puede traer algo que la que ya salió no alcanzó a pedir.
    if (sincronizacionEnVuelo !== null) {
      sincronizacionPendiente = true
      return sincronizacionEnVuelo
    }

    sincronizacionEnVuelo = ejecutarSincronizacion()

    try {
      await sincronizacionEnVuelo
    } finally {
      sincronizacionEnVuelo = null
    }

    if (sincronizacionPendiente) {
      sincronizacionPendiente = false
      agendarSincronizacion()
    }
  }

  async function ejecutarSincronizacion(): Promise<void> {
    if (temporizadorReintento !== null) {
      clearTimeout(temporizadorReintento)
      temporizadorReintento = null
    }

    try {
      const [respuestaViajes, respuestaConductores] = await Promise.all([
        http.get(`/t/${slug}/pedidos/en-turno`),
        http.get(`/t/${slug}/conductores/activos`),
      ])

      fusionarViajes(respuestaViajes.data.data as ViajeEnTurno[])
      fusionarConductores(respuestaConductores.data.data as ConductorActivo[])

      primeraCarga.value = false
      desincronizado.value = false
      intentosFallidos = 0
    } catch {
      // RN-17: no se toca lo que ya está en pantalla. Un fallo de red no puede borrar la operación
      // que el despachador está mirando; solo se avisa de que puede estar vieja (RN-18).
      desincronizado.value = true
      programarReintento()
    }
  }

  function programarReintento() {
    const espera = REINTENTOS_MS[Math.min(intentosFallidos, REINTENTOS_MS.length - 1)]
    intentosFallidos += 1

    if (temporizadorReintento !== null) clearTimeout(temporizadorReintento)
    temporizadorReintento = setTimeout(() => {
      temporizadorReintento = null
      void sincronizar()
    }, espera)
  }

  /** RN-13: fusiona por id. Nunca se vacía la lista para volver a llenarla. */
  function fusionarViajes(lista: ViajeEnTurno[]) {
    const vistos = new Set<number>()

    for (const viaje of lista) {
      vistos.add(viaje.id_pedido)
      const actual = viajes.value.get(viaje.id_pedido)
      viajes.value.set(viaje.id_pedido, actual ? { ...actual, ...viaje } : viaje)
    }

    // Borrar durante la iteración es seguro: el iterador de `Map` contempla que se le quiten claves.
    for (const id of viajes.value.keys()) {
      // Los que están saliendo ya no vienen del servidor —por eso salen—: quitarlos aquí les
      // cortaría la animación a media transición (RN-23).
      if (!vistos.has(id) && !saliendo.value.has(id)) viajes.value.delete(id)
    }
  }

  function fusionarConductores(lista: ConductorActivo[]) {
    const vistos = new Set<number>()

    for (const conductor of lista) {
      vistos.add(conductor.id_conductor)
      const actual = conductores.value.get(conductor.id_conductor)
      conductores.value.set(
        conductor.id_conductor,
        actual ? { ...actual, ...conductor } : conductor,
      )
    }

    for (const id of conductores.value.keys()) {
      if (!vistos.has(id)) conductores.value.delete(id)
    }
  }

  // ---------------------------------------------------------------------------------------------
  // Aplicación de eventos (RN-05 a RN-10)
  // ---------------------------------------------------------------------------------------------

  /** RN-06: el mismo aviso puede llegar dos veces (socket y push). El segundo no hace nada. */
  function yaProcesado(evento: EventoBase): boolean {
    const id = evento.event_id
    if (!id) return false

    if (eventosVistos.has(id)) return true

    eventosVistos.add(id)
    ordenDeEventos.push(id)
    if (ordenDeEventos.length > EVENTOS_RECORDADOS) {
      const viejo = ordenDeEventos.shift()
      if (viejo !== undefined) eventosVistos.delete(viejo)
    }

    return false
  }

  /**
   * RN-28: un envío TEST no se cuela en un Panel LIVE ni al revés. El canal es por tenant, no por
   * ambiente, así que el filtro que en las peticiones hace `AmbienteScope` aquí lo hace esto.
   */
  function esDeOtroAmbiente(evento: EventoBase): boolean {
    return typeof evento.ambiente === 'string' && evento.ambiente !== ambientes.ambiente
  }

  function ignorar(evento: EventoBase): boolean {
    return yaProcesado(evento) || esDeOtroAmbiente(evento)
  }

  function resaltar(clave: string) {
    resaltados.value.add(clave)
    setTimeout(() => resaltados.value.delete(clave), RESALTADO_MS)
  }

  function esFinal(estado: EstadoPedido | undefined): boolean {
    return estado !== undefined && ESTADOS_FINALES.includes(estado)
  }

  /**
   * RN-08: un evento sobre una fila que no está en memoria no es un error, pero si esa fila debería
   * existir es señal de que nos perdimos algo — se pide el estado real una sola vez (RN-11 d).
   */
  function viajeConocido(idPedido: number): ViajeEnTurno | null {
    const viaje = viajes.value.get(idPedido)
    if (!viaje) agendarSincronizacion()
    return viaje ?? null
  }

  function actualizarViaje(idPedido: number, cambios: Partial<ViajeEnTurno>) {
    const viaje = viajes.value.get(idPedido)
    if (!viaje) return

    // RN-09: solo se escriben los campos que vinieron en el evento; el resto se queda como estaba.
    viajes.value.set(idPedido, { ...viaje, ...limpiar(cambios) })
    resaltar(`viaje:${idPedido}`)
  }

  /** Un campo ausente en la carga del evento no significa "ponlo en nulo". */
  function limpiar<T extends object>(cambios: T): Partial<T> {
    return Object.fromEntries(
      Object.entries(cambios).filter(([, valor]) => valor !== undefined),
    ) as Partial<T>
  }

  /**
   * El estado final se escribe **antes** de quitar la fila, no después: es lo que hace que la
   * tarjeta pueda despedirse diciendo "Entregado" (RN-23) y que el detalle abierto muestre en qué
   * terminó el envío en vez de vaciarse de golpe (RN-26).
   */
  function quitarViaje(idPedido: number, estadoFinal: EstadoPedido) {
    if (!viajes.value.has(idPedido)) return

    const viaje = viajes.value.get(idPedido) as ViajeEnTurno
    viajes.value.set(idPedido, { ...viaje, estado: estadoFinal })

    if (estadoFinal !== 'ENTREGADO') {
      viajes.value.delete(idPedido)
      return
    }

    // RN-23: se marca como entregado y sale un instante después. Que una fila se evapore justo
    // cuando alguien iba a tocarla es peor que esperar.
    saliendo.value.add(idPedido)
    setTimeout(() => {
      viajes.value.delete(idPedido)
      saliendo.value.delete(idPedido)
    }, SALIDA_ENTREGADO_MS)
  }

  function actualizarConductor(idConductor: number, cambios: Partial<ConductorActivo>) {
    const conductor = conductores.value.get(idConductor)
    if (!conductor) return

    conductores.value.set(idConductor, { ...conductor, ...limpiar(cambios) })
    resaltar(`conductor:${idConductor}`)
  }

  /** El conductor pasa a "Ocupado": el badge sale de traer un pedido activo (spec tenant/023). */
  function ocuparConductor(
    idConductor: number | null | undefined,
    idPedido: number,
    estado: EstadoPedido,
    seguimiento: Seguimiento | null,
    saldoViajes: number | null | undefined,
  ) {
    if (idConductor === null || idConductor === undefined) return

    const conductor = conductores.value.get(idConductor)
    if (!conductor) {
      agendarSincronizacion()
      return
    }

    const viaje = viajes.value.get(idPedido)

    actualizarConductor(idConductor, {
      saldo_viajes: saldoViajes ?? undefined,
      pedido_asignado: {
        id_pedido: idPedido,
        numero_pedido: viaje?.numero_pedido ?? conductor.pedido_asignado?.numero_pedido ?? '',
        estado,
        direccion_recogida: viaje?.direccion_recogida ?? '',
        latitud_recogida: Number(viaje?.latitud_recogida ?? 0),
        longitud_recogida: Number(viaje?.longitud_recogida ?? 0),
        direccion_entrega: viaje?.direccion_entrega ?? '',
        latitud_entrega: Number(viaje?.latitud_entrega ?? 0),
        longitud_entrega: Number(viaje?.longitud_entrega ?? 0),
        seguimiento,
      },
    })
  }

  function liberarConductor(
    idConductor: number | null | undefined,
    saldoViajes: number | null | undefined,
  ) {
    if (idConductor === null || idConductor === undefined) return

    actualizarConductor(idConductor, {
      pedido_asignado: null,
      saldo_viajes: saldoViajes ?? undefined,
    })
  }

  /** La fila llega resuelta del servidor: se escribe encima de la que hubiera, sin vaciar nada. */
  function insertarViaje(payload: ViajeEnTurno) {
    const actual = viajes.value.get(payload.id_pedido)
    viajes.value.set(payload.id_pedido, actual ? { ...actual, ...payload } : payload)
    resaltar(`viaje:${payload.id_pedido}`)
  }

  // ---------------------------------------------------------------------------------------------
  // Un reductor por evento (RN-07)
  // ---------------------------------------------------------------------------------------------

  interface EventoPedido extends EventoBase {
    id_pedido: number
    estado?: EstadoPedido
    id_conductor?: number | null
    conductor_nombre?: string | null
    seguimiento?: Seguimiento | null
    saldo_viajes?: number | null
  }

  const REDUCTORES: Record<string, (payload: never) => void> = {
    /**
     * El envío acaba de darse de alta. Es el único aviso que llega siempre: `pedido.disponible`
     * depende de que el envío se publique y de que haya conductores elegibles en ese momento, así
     * que un agendado —o uno creado con la flotilla desconectada— no producía ninguno y la fila no
     * aparecía hasta recargar la página.
     */
    'pedido.creado': (payload: ViajeEnTurno & EventoBase) => {
      if (ignorar(payload)) return
      insertarViaje(payload)
    },

    /** Trae el `PedidoResource` completo: la fila se inserta tal cual. */
    'pedido.disponible': (payload: ViajeEnTurno & EventoBase) => {
      if (ignorar(payload)) return
      insertarViaje(payload)
    },

    'pedido.tomado': (payload: EventoPedido) => {
      if (ignorar(payload)) return

      viajeConocido(payload.id_pedido)
      actualizarViaje(payload.id_pedido, {
        estado: payload.estado ?? 'TOMADO',
        id_conductor: payload.id_conductor ?? null,
        conductor_nombre: payload.conductor_nombre ?? null,
      })
      ocuparConductor(
        payload.id_conductor,
        payload.id_pedido,
        payload.estado ?? 'TOMADO',
        payload.seguimiento ?? null,
        payload.saldo_viajes,
      )
    },

    'pedido.estado-cambiado': (payload: EventoPedido) => {
      if (ignorar(payload)) return

      if (esFinal(payload.estado)) {
        quitarViaje(payload.id_pedido, payload.estado as EstadoPedido)
        liberarConductor(payload.id_conductor, payload.saldo_viajes)
        return
      }

      viajeConocido(payload.id_pedido)
      actualizarViaje(payload.id_pedido, { estado: payload.estado })
      ocuparConductor(
        payload.id_conductor,
        payload.id_pedido,
        payload.estado ?? 'TOMADO',
        payload.seguimiento ?? null,
        payload.saldo_viajes,
      )
    },

    'pedido.entregado': (payload: EventoPedido) => {
      if (ignorar(payload)) return

      quitarViaje(payload.id_pedido, 'ENTREGADO')
      liberarConductor(payload.id_conductor, payload.saldo_viajes)
    },

    'pedido.cancelado': (payload: EventoPedido) => {
      if (ignorar(payload)) return

      quitarViaje(payload.id_pedido, 'CANCELADO')
      liberarConductor(payload.id_conductor, payload.saldo_viajes)
    },

    /** Se agotaron las rondas de oferta (spec tenant/020, RN-04): vuelve a PENDIENTE y sin dueño. */
    'pedido.requiere-asignacion-manual': (payload: EventoPedido) => {
      if (ignorar(payload)) return

      viajeConocido(payload.id_pedido)
      actualizarViaje(payload.id_pedido, {
        estado: payload.estado ?? 'PENDIENTE',
        id_conductor: null,
        conductor_nombre: null,
      })
      liberarConductor(payload.id_conductor, payload.saldo_viajes)
    },

    'conductor.disponibilidad-cambiada': (
      payload: EventoBase & {
        id_conductor: number
        disponibilidad: string
        conductor: ConductorActivo | null
      },
    ) => {
      if (ignorar(payload)) return

      if (payload.disponibilidad === 'FUERA_DE_SERVICIO' || payload.conductor === null) {
        conductores.value.delete(payload.id_conductor)
        return
      }

      const actual = conductores.value.get(payload.id_conductor)
      conductores.value.set(
        payload.id_conductor,
        actual ? { ...actual, ...payload.conductor } : payload.conductor,
      )
      resaltar(`conductor:${payload.id_conductor}`)

      if (!actual) {
        ultimaConexionDeConductor.value = {
          id_conductor: payload.id_conductor,
          nombre: payload.conductor.nombre,
          en: Date.now(),
        }
      }
    },

    /** RN-07: se escribe el saldo resultante que manda el servidor, no se le suma el delta. */
    'saldo.acreditado': (payload: EventoBase & { id_conductor: number; saldo_viajes?: number }) => {
      if (ignorar(payload)) return
      if (payload.saldo_viajes === undefined || payload.saldo_viajes === null) {
        agendarSincronizacion()
        return
      }

      actualizarConductor(payload.id_conductor, { saldo_viajes: payload.saldo_viajes })
    },

    /**
     * El evento de mayor frecuencia del sistema (spec tenant/021). Mueve al conductor y nada más:
     * ni resalta la fila ni toca la lista, o el panel entero estaría destellando todo el día.
     */
    'ubicacion.actualizada': (
      payload: EventoBase & { id_conductor: number; latitud: number; longitud: number },
    ) => {
      if (yaProcesado(payload)) return

      const conductor = conductores.value.get(payload.id_conductor)
      if (!conductor) return

      conductores.value.set(payload.id_conductor, {
        ...conductor,
        latitud: payload.latitud,
        longitud: payload.longitud,
      })
    },
  }

  return {
    viajes,
    conductores,
    conexion,
    primeraCarga,
    desincronizado,
    hayDatos,
    viajesOrdenados,
    conductoresOrdenados,
    enLinea,
    ultimaConexionDeConductor,
    estaSaliendo,
    estaResaltado,
    iniciar,
    detener,
    sincronizar,
  }
})
