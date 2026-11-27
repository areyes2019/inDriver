import { reactive } from 'vue'
import { redondearTarifa } from '@/lib/tarifa'
import { ESTADOS_CON_AVISO, mensajeDe, grupoDe } from '../lib/estados'
import { distanciaM, puntoEnTrazo } from '../lib/geo'
import type { ApiCliente } from './cliente'
import type {
  ActualizacionPedido,
  Cliente,
  Coordenada,
  Cotizacion,
  Direccion,
  EstadoPedido,
  Notificacion,
  Pedido,
  Repartidor,
  SolicitudPedido,
} from './tipos'

/**
 * API simulada del prototipo (spec tenant/035, Fase 2). Imita las reglas del backend para que el
 * prototipo se sienta real: zona de servicio, cotización con vencimiento, llave anti-duplicados y un
 * repartidor que acepta, recoge y entrega. Nada sale del navegador.
 */

/** Interruptores del panel "Demo" para revisar los casos especiales. */
export const controlesPrototipo = reactive({
  appApagada: false,
  fueraDeHorario: false,
  conexionCortada: false,
  versionNueva: false,
})

const CENTRO_CELAYA: Coordenada = { lat: 20.5235, lng: -100.8157 }
const RADIO_ZONA_M = 25_000
const TELEFONO_CONOCIDO = '4611234567'
const CORREO_CONOCIDO = 'maria@ejemplo.com'

const REPARTIDOR: Repartidor = {
  nombre: 'Juan Pérez Ramírez',
  telefono: '4612345678',
  color: '#7C3AED',
}

const espera = (ms = 450) => new Promise<void>((r) => setTimeout(r, ms))
const ahoraMenos = (min: number) => new Date(Date.now() - min * 60_000).toISOString()

let cliente: Cliente = {
  id: 1,
  nombre: 'María López',
  telefono: TELEFONO_CONOCIDO,
  email: CORREO_CONOCIDO,
}

let siguienteId = 1050

let direcciones: Direccion[] = [
  {
    id: 1,
    alias: 'Casa',
    direccion: 'Av. Tecnológico 1205, Alameda, Celaya, Gto.',
    lat: 20.5398,
    lng: -100.8166,
    referencias: 'Portón negro, junto a la farmacia',
  },
  {
    id: 2,
    alias: 'Trabajo',
    direccion: 'Blvd. Adolfo López Mateos 1802, Celaya, Gto.',
    lat: 20.5261,
    lng: -100.7987,
    referencias: 'Recepción, piso 2',
  },
]

function pedidoBase(
  id: number,
  estado: EstadoPedido,
  minutosAtras: number,
  extra: Partial<Pedido> = {},
): Pedido {
  return {
    id,
    numero: `PED-${String(id).padStart(6, '0')}`,
    tipo: 'ENVIAR',
    estado,
    recogida: {
      direccion: 'Av. Tecnológico 1205, Alameda, Celaya, Gto.',
      lat: 20.5398,
      lng: -100.8166,
    },
    referenciasRecogida: '',
    contactoRecogida: { nombre: cliente.nombre, telefono: cliente.telefono },
    entrega: {
      direccion: 'Calle Hidalgo 210, Zona Centro, Celaya, Gto.',
      lat: 20.5214,
      lng: -100.8148,
    },
    referenciasEntrega: '',
    recibe: { nombre: 'Laura Martínez', telefono: '4619876543' },
    modalidad: 'REMITENTE_PAGA_ENVIO',
    importeEnvio: 45,
    importeProducto: 0,
    km: 2.6,
    creadoEn: ahoraMenos(minutosAtras),
    programadoPara: null,
    repartidor: estado === 'ENTREGADO' ? REPARTIDOR : null,
    posicion: null,
    sinRepartidor: false,
    ...extra,
  }
}

let pedidos: Pedido[] = [
  pedidoBase(1044, 'ENTREGADO', 60 * 26),
  pedidoBase(1039, 'ENTREGADO', 60 * 24 * 4, {
    tipo: 'RECIBIR',
    recogida: {
      direccion: 'Farmacias Guadalajara, Av. Irrigación, Celaya, Gto.',
      lat: 20.5312,
      lng: -100.8297,
    },
    entrega: {
      direccion: 'Av. Tecnológico 1205, Alameda, Celaya, Gto.',
      lat: 20.5398,
      lng: -100.8166,
    },
    recibe: { nombre: 'María López', telefono: TELEFONO_CONOCIDO },
    modalidad: 'RECEPTOR_PAGA_ENVIO_PRODUCTOS',
    importeProducto: 380,
    importeEnvio: 50,
    km: 3.4,
  }),
  pedidoBase(1031, 'CANCELADO', 60 * 24 * 9, { sinRepartidor: true }),
  pedidoBase(1022, 'ENTREGADO', 60 * 24 * 15, { importeEnvio: 60, km: 5.1 }),
]

let notificaciones: Notificacion[] = [
  {
    id: 3,
    idPedido: 1044,
    titulo: 'Entregado',
    mensaje: 'Tu pedido PED-001044 fue entregado.',
    creadaEn: ahoraMenos(60 * 25),
    leida: true,
  },
]

const llaves = new Map<string, number>()
const oyentes = new Map<number, Set<(c: ActualizacionPedido) => void>>()
const pendientesPorCorte: ActualizacionPedido[] = []
const trazos = new Map<string, Coordenada[]>()

function titulo(slug: string): string {
  if (slug === 'motopanda') return 'MotoPanda'
  return slug
    .split('-')
    .map((p) => p.charAt(0).toUpperCase() + p.slice(1))
    .join(' ')
}

function enZona(p: Coordenada): boolean {
  return distanciaM(p, CENTRO_CELAYA) <= RADIO_ZONA_M
}

function calcular(solicitud: SolicitudPedido): Cotizacion {
  const kmCalle = Math.round((distanciaM(solicitud.recogida, solicitud.entrega) * 1.35) / 100) / 10
  const importeEnvio = redondearTarifa(35 + Math.max(0, kmCalle - 3) * 8)
  const importeProducto =
    solicitud.modalidad === 'RECEPTOR_PAGA_ENVIO_PRODUCTOS' ? solicitud.importeProducto : 0
  return {
    km: Math.max(0.5, kmCalle),
    minutos: Math.max(6, Math.round((kmCalle / 22) * 60) + 4),
    importeEnvio,
    importeProducto,
    total: importeEnvio + importeProducto,
    sello: btoa(JSON.stringify({ c: cliente.id, i: importeEnvio, t: Date.now() })),
    venceEn: new Date(Date.now() + 10 * 60_000).toISOString(),
  }
}

function emitir(cambio: ActualizacionPedido): void {
  const pedido = pedidos.find((p) => p.id === cambio.idPedido)
  if (pedido) {
    if (cambio.estado) pedido.estado = cambio.estado
    if (cambio.repartidor !== undefined) pedido.repartidor = cambio.repartidor
    if (cambio.posicion) pedido.posicion = cambio.posicion
    if (cambio.sinRepartidor !== undefined) pedido.sinRepartidor = cambio.sinRepartidor
    if (cambio.estado && ESTADOS_CON_AVISO.includes(cambio.estado)) {
      notificaciones.unshift({
        id: Date.now(),
        idPedido: pedido.id,
        titulo: mensajeDe(cambio.estado, pedido.sinRepartidor),
        mensaje: `Pedido ${pedido.numero}`,
        creadaEn: new Date().toISOString(),
        leida: false,
      })
    }
  }

  // Con la conexión cortada el aviso no llega: la pantalla se entera por la consulta de respaldo.
  if (controlesPrototipo.conexionCortada) {
    pendientesPorCorte.push(cambio)
    return
  }
  oyentes.get(cambio.idPedido)?.forEach((oyente) => oyente(cambio))
}

/** Un repartidor que acepta a los 6 s, recoge y entrega: unos 70 s en total. */
async function simular(pedido: Pedido): Promise<void> {
  const inicio: Coordenada = { lat: pedido.recogida.lat + 0.009, lng: pedido.recogida.lng - 0.007 }

  await espera(6000)
  if (pedido.estado === 'CANCELADO') return
  emitir({ idPedido: pedido.id, estado: 'TOMADO', repartidor: REPARTIDOR, posicion: inicio })

  await recorrer(pedido, 'recogida', inicio, pedido.recogida, 22)
  emitir({ idPedido: pedido.id, estado: 'ARRIBADO' })
  await espera(5000)

  emitir({ idPedido: pedido.id, estado: 'EN_CAMINO' })
  await recorrer(pedido, 'entrega', pedido.recogida, pedido.entrega, 28)
  emitir({ idPedido: pedido.id, estado: 'ARRIBADO_A_ENTREGA' })
  await espera(5000)

  emitir({ idPedido: pedido.id, estado: 'ENTREGADO', posicion: pedido.entrega })
}

async function recorrer(
  pedido: Pedido,
  tramo: 'recogida' | 'entrega',
  desde: Coordenada,
  hasta: Coordenada,
  pasos: number,
): Promise<void> {
  for (let i = 1; i <= pasos; i++) {
    await espera(1000)
    const trazo = trazos.get(`${pedido.id}:${tramo}`) ?? [desde, hasta]
    emitir({ idPedido: pedido.id, posicion: puntoEnTrazo(trazo, i / pasos) })
  }
}

export const apiSimulada: ApiCliente = {
  async marca(slug) {
    await espera(250)
    return {
      slug,
      nombre: titulo(slug),
      logoUrl: null,
      bannerUrl: null,
      telefonoContacto: '4611002000',
      servicios: { enviar: true, recibir: true },
      disponible: !controlesPrototipo.appApagada,
      centro: CENTRO_CELAYA,
      horario: 'Todos los días de 8:00 a 22:00',
      abiertoAhora: !controlesPrototipo.fueraDeHorario,
    }
  },

  async pedirCodigo(_slug, email, registro) {
    await espera(700)
    if (controlesPrototipo.appApagada) return { ok: false, motivo: 'NO_DISPONIBLE' }
    // RN-10: el servidor revisa que el dominio reciba correo; aquí, un dominio obviamente mal escrito.
    if (/@(gmial|hotmial|gmail\.co)$/i.test(email)) return { ok: false, motivo: 'CORREO_INVALIDO' }
    if (!registro && email !== CORREO_CONOCIDO && email !== cliente.email) {
      return { ok: false, motivo: 'NO_ENCONTRADO' }
    }
    return { ok: true }
  },

  async verificar(_slug, email, codigo, registro) {
    await espera(700)
    if (codigo === '000000') return { ok: false, motivo: 'CODIGO_INCORRECTO' }
    const nuevo = email !== CORREO_CONOCIDO
    if (nuevo) {
      cliente = {
        id: 2,
        nombre: registro?.nombre ?? 'Cliente nuevo',
        telefono: registro?.telefono ?? '',
        email,
      }
      pedidos = []
      notificaciones = []
      direcciones = []
    }
    return { ok: true, token: `demo-${Date.now()}`, cliente, nuevo }
  },

  async salir() {
    await espera(200)
  },

  async perfil() {
    await espera(150)
    return cliente
  },

  async actualizarPerfil(datos) {
    await espera()
    cliente = { ...cliente, ...datos }
    return cliente
  },

  async direcciones() {
    await espera(200)
    return [...direcciones]
  },

  async guardarDireccion(direccion) {
    await espera()
    if (direccion.id) {
      direcciones = direcciones.map((d) =>
        d.id === direccion.id ? ({ ...direccion, id: d.id } as Direccion) : d,
      )
      return direcciones.find((d) => d.id === direccion.id) as Direccion
    }
    const nueva = { ...direccion, id: Date.now() } as Direccion
    direcciones.push(nueva)
    return nueva
  },

  async borrarDireccion(id) {
    await espera(300)
    direcciones = direcciones.filter((d) => d.id !== id)
  },

  async validarPunto(punto) {
    await espera(200)
    return enZona(punto)
  },

  async direccionDe(punto) {
    await espera(300)
    return `Punto elegido en el mapa (${punto.lat.toFixed(4)}, ${punto.lng.toFixed(4)})`
  },

  async cotizar(solicitud) {
    await espera(900)
    if (!enZona(solicitud.recogida))
      return { ok: false, motivo: 'FUERA_DE_ZONA', punto: 'recogida' }
    if (!enZona(solicitud.entrega)) return { ok: false, motivo: 'FUERA_DE_ZONA', punto: 'entrega' }
    return { ok: true, cotizacion: calcular(solicitud) }
  },

  async crearPedido(solicitud, cotizacion, llave) {
    await espera(1000)

    // RN-31: la misma llave devuelve el mismo pedido.
    const existente = llaves.get(llave)
    if (existente !== undefined) {
      const pedido = pedidos.find((p) => p.id === existente)
      if (pedido) return { ok: true, pedido }
    }
    if (controlesPrototipo.appApagada) return { ok: false, motivo: 'NO_DISPONIBLE' }

    // RN-24: con el sello vencido se recalcula; si el precio cambió, el cliente confirma otra vez.
    if (new Date(cotizacion.venceEn).getTime() < Date.now()) {
      const nueva = calcular(solicitud)
      if (nueva.total !== cotizacion.total)
        return { ok: false, motivo: 'PRECIO_CAMBIO', cotizacion: nueva }
    }

    const id = ++siguienteId
    const programadoPara =
      !solicitud.loAntesPosible && solicitud.fecha && solicitud.hora
        ? new Date(`${solicitud.fecha}T${solicitud.hora}:00`).toISOString()
        : null
    const pedido: Pedido = {
      id,
      numero: `PED-${String(id).padStart(6, '0')}`,
      tipo: solicitud.tipo,
      estado: solicitud.loAntesPosible ? 'PUBLICADO' : 'PENDIENTE',
      recogida: solicitud.recogida,
      referenciasRecogida: solicitud.referenciasRecogida,
      contactoRecogida: solicitud.contactoRecogida,
      entrega: solicitud.entrega,
      referenciasEntrega: solicitud.referenciasEntrega,
      recibe: solicitud.recibe,
      modalidad: solicitud.modalidad,
      importeEnvio: cotizacion.importeEnvio,
      importeProducto: cotizacion.importeProducto,
      km: cotizacion.km,
      creadoEn: new Date().toISOString(),
      programadoPara,
      repartidor: null,
      posicion: null,
      sinRepartidor: false,
    }
    pedidos.unshift(pedido)
    llaves.set(llave, id)

    if (solicitud.loAntesPosible) void simular(pedido)
    return { ok: true, pedido }
  },

  async pedidos(grupo) {
    await espera(300)
    return pedidos.filter((p) => grupoDe(p.estado) === grupo).map((p) => ({ ...p }))
  },

  async pedido(id) {
    await espera(200)
    const pedido = pedidos.find((p) => p.id === id)
    return pedido ? { ...pedido } : null
  },

  async cancelar(id) {
    await espera(600)
    const pedido = pedidos.find((p) => p.id === id)
    if (!pedido) throw new Error('Pedido no encontrado')
    emitir({ idPedido: id, estado: 'CANCELADO' })
    return { ...pedido }
  },

  escucharPedido(id, alRecibir) {
    const conjunto = oyentes.get(id) ?? new Set()
    conjunto.add(alRecibir)
    oyentes.set(id, conjunto)
    return () => conjunto.delete(alRecibir)
  },

  estadoConexion() {
    return controlesPrototipo.conexionCortada ? 'reconectando' : 'conectado'
  },

  async notificaciones() {
    await espera(200)
    return notificaciones.map((n) => ({ ...n }))
  },

  async marcarLeida(id) {
    const n = notificaciones.find((x) => x.id === id)
    if (n) n.leida = true
  },

  pistaDeTrazo(idPedido, tramo, trazo) {
    if (trazo.length > 1) trazos.set(`${idPedido}:${tramo}`, trazo)
  },
}

/** Al reanudar la conexión simulada, se entregan los avisos que se quedaron en el camino. */
export function reanudarConexionSimulada(): void {
  controlesPrototipo.conexionCortada = false
  pendientesPorCorte.splice(0).forEach((cambio) => {
    oyentes.get(cambio.idPedido)?.forEach((oyente) => oyente(cambio))
  })
}
