/**
 * Globo con iniciales del conductor (spec tenant/033, secciones 5 y 6). Sin dependencias de Google:
 * arma el SVG y decide el color de la letra; `GoogleProvider` lo convierte en icono.
 */

export const ANCHO_GLOBO = 26
export const ALTO_GLOBO = 32

export const LETRA_CLARA = '#FFFFFF'
export const LETRA_OSCURA = '#111827'

/** Por debajo de este contraste con el relleno, la letra blanca pasa a oscura (sección 6). */
export const CONTRASTE_MINIMO_BLANCO = 3.5

function canal(valor: number): number {
  const c = valor / 255
  return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
}

/** Luminancia relativa de WCAG; `null` si el color no es `#RRGGBB` o `#RGB`. */
function luminancia(color: string): number | null {
  let hex = color.trim().replace(/^#/, '')
  if (/^[0-9a-f]{3}$/i.test(hex)) hex = hex.replace(/./g, (c) => c + c)
  if (!/^[0-9a-f]{6}$/i.test(hex)) return null

  const n = parseInt(hex, 16)
  return 0.2126 * canal(n >> 16) + 0.7152 * canal((n >> 8) & 255) + 0.0722 * canal(n & 255)
}

export function contrasteConBlanco(color: string): number | null {
  const l = luminancia(color)
  return l === null ? null : 1.05 / (l + 0.05)
}

/** Blanca mientras se lea al menos a 3.5:1; si no, casi negra. Un color ilegible se queda en blanca. */
export function colorDeLetra(color: string): string {
  const contraste = contrasteConBlanco(color)
  return contraste !== null && contraste < CONTRASTE_MINIMO_BLANCO ? LETRA_OSCURA : LETRA_CLARA
}

function escaparXml(texto: string): string {
  return texto
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
}

/**
 * Gota de 26×32: círculo de radio 12 centrado en (13, 13) que se cierra en una punta en (13, 31).
 * La punta queda a 1 px del borde para que el trazo blanco no se recorte.
 */
const GOTA = 'M13 31 C9 24 1 20 1 13 A12 12 0 1 1 25 13 C25 20 17 24 13 31 Z'

export function svgGlobo(color: string, iniciales: string): string {
  const relleno = escaparXml(color)
  const letras = escaparXml(iniciales)
  // Dos letras caben más justas que una.
  const tamano = Array.from(iniciales).length > 1 ? 10 : 13

  // Un SVG usado como imagen no puede cargar las fuentes web de la página: Inter se usa si está
  // instalada y, si no, la sans-serif del sistema.
  return (
    `<svg xmlns="http://www.w3.org/2000/svg" width="${ANCHO_GLOBO}" height="${ALTO_GLOBO}" viewBox="0 0 ${ANCHO_GLOBO} ${ALTO_GLOBO}">` +
    `<path d="${GOTA}" fill="${relleno}" stroke="#FFFFFF" stroke-width="2"/>` +
    `<text x="13" y="13" text-anchor="middle" dominant-baseline="central" ` +
    `font-family="Inter, 'Segoe UI', Roboto, Arial, sans-serif" font-size="${tamano}" font-weight="700" letter-spacing="-0.4" ` +
    `fill="${colorDeLetra(color)}">${letras}</text>` +
    `</svg>`
  )
}

export function urlGlobo(color: string, iniciales: string): string {
  return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svgGlobo(color, iniciales))}`
}
