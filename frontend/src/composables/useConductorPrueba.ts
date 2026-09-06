import { ref } from 'vue'
import axios from 'axios'
import { API_URL } from '@/lib/http'
import mapService from '@/services/maps/MapService'

interface OfertaPedido {
  id_pedido: number
  latitud_recogida: number
  longitud_recogida: number
  latitud_entrega: number
  longitud_entrega: number
}

export type EstadoDemo =
  | 'inactivo'
  | 'conectando'
  | 'esperando_oferta'
  | 'aceptando'
  | 'en_recogida'
  | 'en_camino'
  | 'llegando_entrega'
  | 'entregado'
  | 'detenido'
  | 'error'

/**
 * Conductor virtual del "modo prueba" (Panel): actúa como el teléfono de un conductor real,
 * llamando exactamente a los mismos endpoints que usaría panda_express (aceptar, ubicación,
 * estado) con el token de conductor emitido al crearlo — el backend nunca distingue esto de un
 * conductor real. La ruta se recorre solo entre recogida y entrega: el objetivo es ver el
 * tracking real en el mapa, no simular el trayecto de acercamiento del conductor.
 */
export function useConductorPrueba(slug: string, token: string) {
  const estado = ref<EstadoDemo>('inactivo')
  const log = ref<string[]>([])
  let detenido = false

  const api = axios.create({
    baseURL: API_URL,
    headers: { Authorization: `Bearer ${token}` },
  })

  function registrar(mensaje: string) {
    log.value.push(mensaje)
  }

  function esperar(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms))
  }

  async function conectar() {
    estado.value = 'conectando'
    registrar('Conectando...')
    await api.post(`/t/${slug}/conductor/estado`, { estado: 'ONLINE' })
    registrar('En línea.')
  }

  async function esperarOferta(): Promise<OfertaPedido> {
    estado.value = 'esperando_oferta'
    registrar('Esperando una oferta de envío...')

    while (!detenido) {
      const { data } = await api.get(`/t/${slug}/conductor/pedidos/disponibles`)
      const ofertas = data.data as OfertaPedido[]
      if (ofertas.length > 0) return ofertas[0] as OfertaPedido
      await esperar(3000)
    }

    throw new Error('DETENIDO')
  }

  async function aceptar(oferta: OfertaPedido) {
    estado.value = 'aceptando'
    registrar(`Oferta recibida (pedido #${oferta.id_pedido}). Aceptando...`)
    await api.post(`/t/${slug}/conductor/pedidos/${oferta.id_pedido}/aceptar`)
    registrar('Envío aceptado.')
  }

  async function cambiarEstadoPedido(idPedido: number, nuevoEstado: string) {
    await api.post(`/t/${slug}/conductor/pedidos/${idPedido}/estado`, { estado: nuevoEstado })
  }

  /** Igual que `useSimulator.js` en panda_express: a mayor velocidad, menor intervalo entre puntos. */
  async function recorrer(
    origen: { lat: number; lng: number },
    destino: { lat: number; lng: number },
  ) {
    const puntos = await mapService.getRoutePath(origen, destino)
    const velocidadKmh = 60
    const intervalMs = Math.max(150, 1000 - velocidadKmh * 8)

    for (const punto of puntos) {
      if (detenido) return
      await api.post(`/t/${slug}/conductor/ubicacion`, { latitud: punto.lat, longitud: punto.lng })
      await esperar(intervalMs)
    }
  }

  async function correr() {
    detenido = false
    log.value = []

    try {
      await conectar()
      const oferta = await esperarOferta()
      await aceptar(oferta)

      await esperar(1500)
      estado.value = 'en_recogida'
      registrar('Llegando al punto de recogida...')
      await cambiarEstadoPedido(oferta.id_pedido, 'ARRIBADO')

      await esperar(1000)
      registrar('Paquete recogido. Iniciando el recorrido hacia la entrega...')
      await cambiarEstadoPedido(oferta.id_pedido, 'EN_CAMINO')

      estado.value = 'en_camino'
      await recorrer(
        { lat: oferta.latitud_recogida, lng: oferta.longitud_recogida },
        { lat: oferta.latitud_entrega, lng: oferta.longitud_entrega },
      )
      if (detenido) return

      estado.value = 'llegando_entrega'
      registrar('Llegó al punto de entrega.')
      await cambiarEstadoPedido(oferta.id_pedido, 'ARRIBADO_A_ENTREGA')

      await esperar(1000)
      await cambiarEstadoPedido(oferta.id_pedido, 'ENTREGADO')
      estado.value = 'entregado'
      registrar('Entregado. El conductor queda libre para el siguiente envío.')
    } catch (err) {
      if (detenido) return
      estado.value = 'error'
      registrar(
        axios.isAxiosError(err)
          ? (err.response?.data?.message ?? 'Ocurrió un error inesperado.')
          : 'Ocurrió un error inesperado.',
      )
    }
  }

  function detener() {
    detenido = true
    estado.value = 'detenido'
    registrar('Demo detenida.')
  }

  return { estado, log, correr, detener }
}
