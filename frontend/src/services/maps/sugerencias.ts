/**
 * El estado de una sugerencia de Google en México: el penúltimo tramo del texto, sin código postal
 * ("…, Celaya, Gto., México" → "Gto."). `null` si el texto no tiene esa forma.
 *
 * Lo usa `GoogleProvider` para descartar sugerencias de otro estado que llegan sin distancia.
 */
export function estadoDeSugerencia(texto: string): string | null {
  const partes = texto.split(',').map((parte) => parte.trim())
  if (partes.length < 3 || partes[partes.length - 1] !== 'México') return null
  const estado = (partes[partes.length - 2] ?? '').replace(/\d+/g, '').trim()
  return estado === '' ? null : estado
}
