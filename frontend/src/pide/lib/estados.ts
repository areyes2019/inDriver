import type { EstadoPedido, GrupoPedidos } from '../api/tipos.ts'

/** Mensaje al cliente por estado interno (spec tenant/035, RN-40). */
export const MENSAJE_POR_ESTADO: Readonly<Record<EstadoPedido, string>> = {
  PENDIENTE: 'Buscando repartidor',
  PUBLICADO: 'Buscando repartidor',
  TOMADO: 'Tu repartidor va por el paquete',
  ARRIBADO: 'Tu repartidor llegó a recoger',
  EN_CAMINO: 'Tu paquete va en camino',
  ARRIBADO_A_ENTREGA: 'Tu repartidor llegó a la entrega',
  ENTREGADO: 'Entregado',
  INCIDENCIA: 'Hubo un problema con tu envío. La flotilla te contactará',
  EN_REGRESO: 'Tu paquete regresa con quien lo envió',
  DEVUELTO: 'Tu paquete fue devuelto',
  CANCELADO: 'Pedido cancelado',
}

/** Los 5 puntos de la línea de progreso (RN-40). */
export const PASOS_PROGRESO = ['Buscando', 'Recogida', 'En camino', 'Entrega', 'Entregado'] as const

const PASO_POR_ESTADO: Readonly<Partial<Record<EstadoPedido, number>>> = {
  PENDIENTE: 0,
  PUBLICADO: 0,
  TOMADO: 1,
  ARRIBADO: 1,
  EN_CAMINO: 2,
  ARRIBADO_A_ENTREGA: 3,
  ENTREGADO: 4,
}

/** Índice del punto actual en la línea de progreso, o `null` si el estado no la usa. */
export function pasoDeProgreso(estado: EstadoPedido): number | null {
  return PASO_POR_ESTADO[estado] ?? null
}

export function mensajeDe(estado: EstadoPedido, sinRepartidor = false): string {
  if (estado === 'CANCELADO' && sinRepartidor) return 'No encontramos repartidor disponible'
  return MENSAJE_POR_ESTADO[estado]
}

export function estaBuscando(estado: EstadoPedido): boolean {
  return estado === 'PENDIENTE' || estado === 'PUBLICADO'
}

/** RN-36: el cliente solo cancela mientras nadie ha aceptado. */
export function clientePuedeCancelar(estado: EstadoPedido): boolean {
  return estaBuscando(estado)
}

/** RN-42: la moto solo se ve entre que el repartidor acepta y llega a la entrega. */
export function muestraRepartidor(estado: EstadoPedido): boolean {
  return (
    estado === 'TOMADO' ||
    estado === 'ARRIBADO' ||
    estado === 'EN_CAMINO' ||
    estado === 'ARRIBADO_A_ENTREGA'
  )
}

/** Tramo que se dibuja: punteado hacia la recogida, continuo hacia la entrega. */
export function tramoDe(estado: EstadoPedido): 'recogida' | 'entrega' | null {
  if (estado === 'TOMADO' || estado === 'ARRIBADO') return 'recogida'
  if (estado === 'EN_CAMINO' || estado === 'ARRIBADO_A_ENTREGA') return 'entrega'
  return null
}

export function grupoDe(estado: EstadoPedido): GrupoPedidos {
  if (estado === 'ENTREGADO' || estado === 'DEVUELTO') return 'completados'
  if (estado === 'CANCELADO') return 'cancelados'
  return 'activos'
}

/** Estados que disparan un aviso al cliente (RN-51). */
export const ESTADOS_CON_AVISO: readonly EstadoPedido[] = [
  'TOMADO',
  'ARRIBADO',
  'EN_CAMINO',
  'ENTREGADO',
  'CANCELADO',
]
