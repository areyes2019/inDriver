import type { ApiCliente } from './cliente'
import { apiSimulada } from './mock'
import type {
  ActualizacionPedido,
  Cliente,
  Cotizacion,
  Direccion,
  Marca,
  Pedido,
  ResultadoCodigo,
  ResultadoCotizar,
  ResultadoCrear,
  ResultadoVerificar,
  SolicitudPedido,
} from './tipos'
import { useMarcaStore } from '../stores/marca'

/**
 * API real de la app de clientes (spec tenant/035, sección 8). Va entrando por fases:
 * - Fase 3: marca, código, verificación, salir y perfil.
 * - Fase 4: zona de servicio, dirección de un punto, cotización y direcciones favoritas.
 * - Fase 5: crear (con llave anti-duplicados), listar, ver y cancelar pedidos. Mientras llega el
 *   canal en tiempo real (Fase 6), el seguimiento consulta el pedido cada 15 segundos.
 * Lo demás sigue en la simulada hasta su fase; los avisos (Fase 8) quedan vacíos.
 *
 * Usa `fetch` y no el `http` (axios) del Panel: la app autentica con token Bearer, no con la cookie
 * de sesión, y así no descarga el código del Panel.
 */

const API_URL: string = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api/v1'

/** La sesión ya no vale (token revocado, cliente inactivo o de otra flotilla). */
export class SesionVencida extends Error {}

interface Respuesta<T> {
  estado: number
  datos: T
}

async function llamar<T>(
  slug: string,
  ruta: string,
  opciones: {
    metodo?: string
    cuerpo?: unknown
    conToken?: boolean
    encabezados?: Record<string, string>
  } = {},
): Promise<Respuesta<T>> {
  const encabezados: Record<string, string> = {
    Accept: 'application/json',
    ...opciones.encabezados,
  }
  if (opciones.cuerpo !== undefined) encabezados['Content-Type'] = 'application/json'
  if (opciones.conToken) {
    const token = useMarcaStore().almacen.leer('token')
    if (token) encabezados.Authorization = `Bearer ${token}`
  }

  const respuesta = await fetch(`${API_URL}/t/${encodeURIComponent(slug)}/cliente${ruta}`, {
    method: opciones.metodo ?? 'GET',
    headers: encabezados,
    body: opciones.cuerpo === undefined ? undefined : JSON.stringify(opciones.cuerpo),
    credentials: 'omit',
  })

  if (opciones.conToken && respuesta.status === 401) throw new SesionVencida()

  const texto = await respuesta.text()
  const datos = (texto ? JSON.parse(texto) : null) as T
  return { estado: respuesta.status, datos }
}

function slugActual(): string {
  return useMarcaStore().slug
}

interface ClienteApi {
  id_cliente: number
  nombre: string
  telefono: string | null
  email: string
}

function aCliente(c: ClienteApi): Cliente {
  return { id: c.id_cliente, nombre: c.nombre, telefono: c.telefono ?? '', email: c.email }
}

interface MarcaApi {
  slug: string
  nombre: string
  logo_url: string | null
  banner_url: string | null
  telefono_contacto: string | null
  servicios: { enviar: boolean; recibir: boolean }
  disponible: boolean
  centro: { lat: number; lng: number }
  limites: Marca['limites']
  horario: string
  abierto_ahora: boolean
}

export const apiReal: ApiCliente = {
  ...apiSimulada,

  async marca(slug) {
    const { estado, datos } = await llamar<MarcaApi>(slug, '/marca')
    if (estado !== 200) throw new Error(`No se pudo cargar la flotilla (${estado})`)
    const marca: Marca = {
      slug: datos.slug,
      nombre: datos.nombre,
      logoUrl: datos.logo_url,
      bannerUrl: datos.banner_url,
      telefonoContacto: datos.telefono_contacto,
      servicios: datos.servicios,
      disponible: datos.disponible,
      centro: datos.centro,
      limites: datos.limites,
      horario: datos.horario,
      abiertoAhora: datos.abierto_ahora,
    }
    return marca
  },

  async pedirCodigo(slug, email, registro): Promise<ResultadoCodigo> {
    const { estado, datos } = await llamar<{ motivo?: string }>(slug, '/codigo', {
      metodo: 'POST',
      cuerpo: { email, modo: registro ? 'registro' : 'entrar' },
    })
    if (estado === 200) return { ok: true }
    if (estado === 404) return { ok: false, motivo: 'NO_ENCONTRADO' }
    if (estado === 429) return { ok: false, motivo: 'LIMITE' }
    if (estado === 403) return { ok: false, motivo: 'NO_DISPONIBLE' }
    if (estado === 422) return { ok: false, motivo: 'CORREO_INVALIDO' }
    void datos
    return { ok: false, motivo: 'NO_SE_PUDO_ENVIAR' }
  },

  async verificar(slug, email, codigo, registro): Promise<ResultadoVerificar> {
    const { estado, datos } = await llamar<{
      token?: string
      cliente?: ClienteApi
      nuevo?: boolean
      motivo?: string
    }>(slug, '/verificar', {
      metodo: 'POST',
      cuerpo: { email, codigo, ...(registro ?? {}) },
    })
    if (estado === 200 && datos.token && datos.cliente) {
      return {
        ok: true,
        token: datos.token,
        cliente: aCliente(datos.cliente),
        nuevo: !!datos.nuevo,
      }
    }
    if (estado === 429) return { ok: false, motivo: 'BLOQUEADO' }
    if (estado === 403) return { ok: false, motivo: 'CLIENTE_INACTIVO' }
    return {
      ok: false,
      motivo: datos?.motivo === 'CODIGO_VENCIDO' ? 'CODIGO_VENCIDO' : 'CODIGO_INCORRECTO',
    }
  },

  async salir() {
    try {
      await llamar(slugActual(), '/salir', { metodo: 'POST', conToken: true })
    } catch {
      // Si el token ya no servía, la sesión ya estaba cerrada del lado del servidor.
    }
  },

  async perfil() {
    const { estado, datos } = await llamar<ClienteApi>(slugActual(), '/perfil', { conToken: true })
    if (estado !== 200) throw new SesionVencida()
    return aCliente(datos)
  },

  async actualizarPerfil(cambios) {
    const { estado, datos } = await llamar<ClienteApi>(slugActual(), '/perfil', {
      metodo: 'PUT',
      cuerpo: cambios,
      conToken: true,
    })
    if (estado !== 200) throw new Error('No se pudieron guardar tus datos')
    return aCliente(datos)
  },

  async validarPunto(punto) {
    const { estado, datos } = await llamar<{ cubre: boolean }>(slugActual(), '/zona', {
      metodo: 'POST',
      cuerpo: { lat: punto.lat, lng: punto.lng },
      conToken: true,
    })
    return estado === 200 && datos.cubre
  },

  async direccionDe(punto) {
    const { estado, datos } = await llamar<{ direccion: string }>(slugActual(), '/direccion', {
      metodo: 'POST',
      cuerpo: { lat: punto.lat, lng: punto.lng },
      conToken: true,
    })
    return estado === 200 ? datos.direccion : 'Punto elegido en el mapa'
  },

  async cotizar(s): Promise<ResultadoCotizar> {
    const { estado, datos } = await llamar<CotizacionApi & { motivo?: string; punto?: string }>(
      slugActual(),
      '/cotizar',
      {
        metodo: 'POST',
        conToken: true,
        cuerpo: {
          tipo: s.tipo,
          recogida: { lat: s.recogida.lat, lng: s.recogida.lng },
          entrega: { lat: s.entrega.lat, lng: s.entrega.lng },
          modalidad: s.modalidad,
          importe_producto: s.importeProducto || null,
          lo_antes_posible: s.loAntesPosible,
          ...(s.loAntesPosible ? {} : { fecha: s.fecha, hora: s.hora }),
        },
      },
    )
    if (estado === 200) return { ok: true, cotizacion: aCotizacion(datos) }
    if (datos?.motivo === 'FUERA_DE_ZONA') {
      return {
        ok: false,
        motivo: 'FUERA_DE_ZONA',
        punto: datos.punto === 'recogida' ? 'recogida' : 'entrega',
      }
    }
    if (datos?.motivo === 'SIN_TARIFAS') return { ok: false, motivo: 'SIN_TARIFAS' }
    return { ok: false, motivo: 'SIN_RUTA' }
  },

  async direcciones() {
    const { estado, datos } = await llamar<{ data: DireccionApi[] }>(slugActual(), '/direcciones', {
      conToken: true,
    })
    if (estado !== 200) throw new Error('No se pudieron cargar tus direcciones')
    return datos.data.map(aDireccion)
  },

  async guardarDireccion(d) {
    const ruta = d.id ? `/direcciones/${d.id}` : '/direcciones'
    const { estado, datos } = await llamar<DireccionApi>(slugActual(), ruta, {
      metodo: d.id ? 'PUT' : 'POST',
      conToken: true,
      cuerpo: {
        alias: d.alias,
        direccion: d.direccion,
        lat: d.lat,
        lng: d.lng,
        referencias: d.referencias || null,
      },
    })
    if (estado !== 200 && estado !== 201) throw new Error('No se pudo guardar la dirección')
    return aDireccion(datos)
  },

  async crearPedido(s, cotizacion, llave): Promise<ResultadoCrear> {
    let sello = cotizacion.sello
    // Reintentos: "se está procesando" (RN-31) y un sello vencido con el mismo precio (RN-24).
    for (let intento = 0; intento < 6; intento++) {
      const { estado, datos } = await llamar<RespuestaCrear>(slugActual(), '/pedidos', {
        metodo: 'POST',
        conToken: true,
        encabezados: { 'Idempotency-Key': llave },
        cuerpo: { ...cuerpoSolicitud(s), sello },
      })
      if (estado === 200 || estado === 201) return { ok: true, pedido: aPedido(datos as PedidoApi) }

      const error = datos as ErrorCrear | null
      if (estado === 409 && error?.motivo === 'PROCESANDO') {
        await new Promise((listo) => setTimeout(listo, 2000))
        continue
      }
      if (estado === 409 && error?.motivo === 'SELLO_VENCIDO' && error.cotizacion) {
        const nueva = aCotizacion(error.cotizacion)
        if (nueva.total !== cotizacion.total) {
          return { ok: false, motivo: 'PRECIO_CAMBIO', cotizacion: nueva }
        }
        sello = nueva.sello
        continue
      }
      if (estado === 429) return { ok: false, motivo: 'LIMITE' }
      if (estado === 403) return { ok: false, motivo: 'NO_DISPONIBLE' }
      if (error?.motivo === 'SIN_SERVICIO' || error?.motivo === 'FUERA_DE_HORARIO') {
        return { ok: false, motivo: error.motivo }
      }
      throw new Error(`No se pudo crear el pedido (${error?.motivo ?? estado})`)
    }
    throw new Error('El pedido sigue en proceso')
  },

  async pedidos(grupo) {
    const { estado, datos } = await llamar<{ data: PedidoApi[] }>(
      slugActual(),
      `/pedidos?grupo=${grupo}`,
      { conToken: true },
    )
    if (estado !== 200) throw new Error('No se pudieron cargar tus pedidos')
    return datos.data.map(aPedido)
  },

  async pedido(id) {
    const { estado, datos } = await llamar<PedidoApi>(slugActual(), `/pedidos/${id}`, {
      conToken: true,
    })
    if (estado === 404) return null
    if (estado !== 200) throw new Error('No se pudo cargar el pedido')
    return aPedido(datos)
  },

  async cancelar(id) {
    const { estado, datos } = await llamar<PedidoApi & { pedido?: PedidoApi }>(
      slugActual(),
      `/pedidos/${id}/cancelar`,
      { metodo: 'POST', conToken: true },
    )
    if (estado === 200) return aPedido(datos)
    // RN-37: un repartidor lo tomó justo antes; la pantalla se actualiza con ese estado.
    if (estado === 409 && datos.pedido) throw new YaAsignado(aPedido(datos.pedido))
    throw new Error('No se pudo cancelar el pedido')
  },

  /**
   * Hasta la Fase 6 no hay canal en tiempo real: se consulta el pedido cada 15 segundos (lo mismo
   * que hará el respaldo de D15) y se avisa solo lo que cambió.
   */
  escucharPedido(id, alRecibir) {
    let anterior: Pedido | null = null
    let activo = true
    const consultar = async (): Promise<void> => {
      if (!activo || document.hidden) return
      try {
        const actual = await apiReal.pedido(id)
        if (!actual || !activo) return
        const cambio = diferencia(anterior, actual)
        anterior = actual
        if (cambio) alRecibir(cambio)
      } catch {
        // Sin red: se intenta en la siguiente vuelta.
      }
    }
    void consultar()
    const reloj = setInterval(consultar, 15_000)
    return () => {
      activo = false
      clearInterval(reloj)
    }
  },

  estadoConexion() {
    return 'conectado'
  },

  // Los avisos llegan en la Fase 8: mientras tanto, la campanita queda vacía.
  async notificaciones() {
    return []
  },

  async marcarLeida() {},

  async borrarDireccion(id) {
    const { estado } = await llamar(slugActual(), `/direcciones/${id}`, {
      metodo: 'DELETE',
      conToken: true,
    })
    if (estado !== 204) throw new Error('No se pudo borrar la dirección')
  },
}

/** Un repartidor tomó el pedido justo antes de cancelar (RN-37). */
export class YaAsignado extends Error {
  constructor(public readonly pedido: Pedido) {
    super('El pedido ya tiene repartidor')
  }
}

function cuerpoSolicitud(s: SolicitudPedido) {
  return {
    tipo: s.tipo,
    recogida: { lat: s.recogida.lat, lng: s.recogida.lng, direccion: s.recogida.direccion },
    entrega: { lat: s.entrega.lat, lng: s.entrega.lng, direccion: s.entrega.direccion },
    referencias_recogida: s.referenciasRecogida || null,
    referencias_entrega: s.referenciasEntrega || null,
    contacto_recogida: { nombre: s.contactoRecogida.nombre, telefono: s.contactoRecogida.telefono },
    recibe: { nombre: s.recibe.nombre, telefono: s.recibe.telefono },
    modalidad: s.modalidad,
    importe_producto: s.importeProducto || null,
    lo_antes_posible: s.loAntesPosible,
    ...(s.loAntesPosible ? {} : { fecha: s.fecha, hora: s.hora }),
  }
}

function aCotizacion(c: CotizacionApi): Cotizacion {
  return {
    km: c.km,
    // Sin minutos de Google, un estimado de ciudad: 22 km/h y 4 minutos para salir.
    minutos: c.minutos ?? Math.max(6, Math.round((c.km / 22) * 60) + 4),
    importeEnvio: c.importe_envio,
    importeProducto: c.importe_producto,
    total: c.total,
    sello: c.sello,
    venceEn: c.vence_en,
  }
}

interface ErrorCrear {
  motivo?: string
  cotizacion?: CotizacionApi
}

type RespuestaCrear = PedidoApi | ErrorCrear

interface PuntoApi {
  direccion: string
  lat: number
  lng: number
}

interface PedidoApi {
  id: number
  numero: string
  tipo: Pedido['tipo']
  estado: Pedido['estado']
  recogida: PuntoApi
  referencias_recogida: string
  contacto_recogida: { nombre: string; telefono: string }
  entrega: PuntoApi
  referencias_entrega: string
  recibe: { nombre: string; telefono: string }
  modalidad: Pedido['modalidad']
  importe_envio: number
  importe_producto: number
  km: number | null
  creado_en: string
  programado_para: string | null
  repartidor: { nombre: string; telefono: string; color: string } | null
  posicion: { lat: number; lng: number } | null
  sin_repartidor: boolean
}

function aPedido(p: PedidoApi): Pedido {
  return {
    id: p.id,
    numero: p.numero,
    tipo: p.tipo,
    estado: p.estado,
    recogida: p.recogida,
    referenciasRecogida: p.referencias_recogida,
    contactoRecogida: p.contacto_recogida,
    entrega: p.entrega,
    referenciasEntrega: p.referencias_entrega,
    recibe: p.recibe,
    modalidad: p.modalidad,
    importeEnvio: p.importe_envio,
    importeProducto: p.importe_producto,
    km: p.km,
    creadoEn: p.creado_en,
    programadoPara: p.programado_para,
    repartidor: p.repartidor,
    posicion: p.posicion,
    sinRepartidor: p.sin_repartidor,
  }
}

/** Lo que cambió entre dos consultas del mismo pedido, o `null` si nada. */
function diferencia(antes: Pedido | null, ahora: Pedido): ActualizacionPedido | null {
  const cambio: ActualizacionPedido = { idPedido: ahora.id }
  if (!antes || antes.estado !== ahora.estado) cambio.estado = ahora.estado
  if (JSON.stringify(antes?.repartidor ?? null) !== JSON.stringify(ahora.repartidor)) {
    cambio.repartidor = ahora.repartidor
  }
  if (!antes || antes.sinRepartidor !== ahora.sinRepartidor) {
    cambio.sinRepartidor = ahora.sinRepartidor
  }
  const p = ahora.posicion
  if (p && (antes?.posicion?.lat !== p.lat || antes?.posicion?.lng !== p.lng)) cambio.posicion = p
  return Object.keys(cambio).length > 1 ? cambio : null
}

interface CotizacionApi {
  km: number
  minutos: number | null
  importe_envio: number
  importe_producto: number
  total: number
  sello: string
  vence_en: string
}

interface DireccionApi {
  id: number
  alias: string
  direccion: string
  lat: number
  lng: number
  referencias: string
}

function aDireccion(d: DireccionApi): Direccion {
  return {
    id: d.id,
    alias: d.alias,
    direccion: d.direccion,
    lat: d.lat,
    lng: d.lng,
    referencias: d.referencias,
  }
}
