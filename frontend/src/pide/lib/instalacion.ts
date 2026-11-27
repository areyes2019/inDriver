/**
 * Instalación de la PWA (spec tenant/035, D7 corregida): el cliente abre la liga, instala y después se
 * registra. Lógica pura, para probarla sin navegador.
 */

/** La flotilla de una ruta `/pide/{slug}/...`, o `null`. */
export function slugDeRuta(ruta: string): string | null {
  const coincidencia = /^\/pide\/([a-z0-9-]+)(?:\/|$)/i.exec(ruta)
  return coincidencia?.[1] ?? null
}

/**
 * La bienvenida con "Instalar" se muestra a quien todavía no tiene sesión, no abrió la app instalada
 * y no eligió "Continuar en el navegador".
 */
export function debeMostrarBienvenida(estado: {
  conSesion: boolean
  instalada: boolean
  eligioNavegador: boolean
}): boolean {
  return !estado.conSesion && !estado.instalada && !estado.eligioNavegador
}

export type Plataforma = 'iphone' | 'android' | 'otra'

export function plataformaDe(userAgent: string, maxTouchPoints = 0): Plataforma {
  if (/iphone|ipad|ipod/i.test(userAgent)) return 'iphone'
  // iPadOS se presenta como Mac, pero con pantalla táctil.
  if (/macintosh/i.test(userAgent) && maxTouchPoints > 1) return 'iphone'
  if (/android/i.test(userAgent)) return 'android'
  return 'otra'
}
