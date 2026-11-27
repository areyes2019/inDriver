/**
 * Teléfonos de México (spec tenant/035, RN-10). La app solo pide los 10 dígitos; el servidor es
 * quien lo pasa a E.164. Esto es solo para capturar y mostrar.
 */

/** Deja solo los dígitos y quita los prefijos viejos que la gente todavía escribe. */
export function soloDiezDigitos(texto: string): string {
  let digitos = texto.replace(/\D/g, '')
  if (digitos.length === 13 && digitos.startsWith('521')) digitos = digitos.slice(3)
  else if (digitos.length === 12 && digitos.startsWith('52')) digitos = digitos.slice(2)
  else if (digitos.length === 13 && (digitos.startsWith('044') || digitos.startsWith('045')))
    digitos = digitos.slice(3)
  return digitos.slice(0, 10)
}

export function esTelefonoValido(texto: string): boolean {
  return /^\d{10}$/.test(soloDiezDigitos(texto))
}

/** `4611234567` → `461 123 4567`. */
export function formatoTelefono(texto: string): string {
  const d = soloDiezDigitos(texto)
  if (d.length <= 3) return d
  if (d.length <= 6) return `${d.slice(0, 3)} ${d.slice(3)}`
  return `${d.slice(0, 3)} ${d.slice(3, 6)} ${d.slice(6)}`
}
