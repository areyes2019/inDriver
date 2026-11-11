/**
 * Iniciales de un conductor (spec tenant/033, sección 4). Es la única regla del sistema: el mapa usa
 * el resultado completo y la lista de Flotilla solo su primer carácter.
 *
 * `ConductorActivoResource` manda `nombre` como "{nombre} {apellido_paterno}", así que se toma la
 * primera palabra y la última: un nombre compuesto ("Juan Carlos Pérez") no se confunde con el
 * apellido, y las partículas ("María de la Cruz") no cuentan.
 */

const PARTICULAS = new Set(['de', 'del', 'la', 'las', 'los', 'y'])

/** Sin nombre el globo no puede quedar en blanco (D-19). */
export const SIN_INICIALES = '?'

function primeraLetra(palabra: string): string {
  // `Array.from` respeta caracteres fuera del plano básico; `charAt` los partiría a la mitad.
  return Array.from(palabra)[0]?.toLocaleUpperCase('es-MX') ?? ''
}

export function iniciales(nombre: string): string {
  const palabras = nombre
    .trim()
    .split(/\s+/)
    .filter((palabra) => palabra !== '' && !PARTICULAS.has(palabra.toLocaleLowerCase('es-MX')))

  if (palabras.length === 0) return SIN_INICIALES
  if (palabras.length === 1) return primeraLetra(palabras[0] as string)

  return primeraLetra(palabras[0] as string) + primeraLetra(palabras[palabras.length - 1] as string)
}
