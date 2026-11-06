/**
 * Las fases que ve la gente, traducidas desde los estados internos del pedido. La máquina de estados
 * completa está en la spec tenant/006: las pantallas muestran la fase, no el estado.
 */
export const FASES_POR_ESTADO: Readonly<Record<string, string>> = {
  PENDIENTE: 'Pendiente',
  PUBLICADO: 'Publicado',
  TOMADO: 'En recogida',
  ARRIBADO: 'En recogida',
  EN_CAMINO: 'En entrega',
  ARRIBADO_A_ENTREGA: 'En entrega',
  EN_REGRESO: 'En regreso',
  ENTREGADO: 'Completado',
  DEVUELTO: 'Devuelto',
  CANCELADO: 'Cancelado',
  INCIDENCIA: 'Incidencia',
}

const ESTADOS_CANCELABLES = new Set(['PENDIENTE', 'PUBLICADO', 'TOMADO', 'ARRIBADO'])
const ESTADOS_CON_PAQUETE = new Set(['EN_CAMINO', 'ARRIBADO_A_ENTREGA', 'EN_REGRESO'])

export function faseDe(estado: string): string {
  return FASES_POR_ESTADO[estado] ?? estado
}

/** Solo se cancela en Pendiente, Publicado y En recogida. */
export function sePuedeCancelar(estado: string): boolean {
  return ESTADOS_CANCELABLES.has(estado)
}

/** Con el paquete encima no se cancela: la única salida, además de entregar, es la incidencia. */
export function sePuedeCerrarPorIncidencia(estado: string): boolean {
  return ESTADOS_CON_PAQUETE.has(estado)
}
