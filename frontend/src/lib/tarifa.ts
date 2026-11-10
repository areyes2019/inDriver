/**
 * Lleva el total del viaje a un múltiplo de $5, con mínimo de $5 (spec tenant/006, "Redondeo del
 * total a múltiplos de $5"): si pasa menos de $1.50 del múltiplo inferior baja a él; si pasa $1.50 o
 * más, sube al siguiente.
 *
 * Opera en centavos enteros para que un `66.50` que llega como `66.4999999` no baje a 65. Es la
 * misma operación que `TarifaService::redondear()` en el backend: si cambia aquí, cambia allá.
 */
export function redondearTarifa(total: number): number {
  const centavos = Math.max(0, Math.round(total * 100))
  return Math.max(5, Math.floor((centavos + 350) / 500) * 5)
}
