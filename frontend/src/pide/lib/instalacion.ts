/**
 * Instalación de la PWA (spec tenant/035, D7): **la instalación es obligatoria**. En el navegador, la
 * app solo muestra la bienvenida con "Instalar"; todo lo demás se usa desde la app instalada. Lógica
 * pura, para probarla sin navegador.
 */

/** La flotilla de una ruta `/pide/{slug}/...`, o `null`. */
export function slugDeRuta(ruta: string): string | null {
  const coincidencia = /^\/pide\/([a-z0-9-]+)(?:\/|$)/i.exec(ruta)
  return coincidencia?.[1] ?? null
}

/**
 * Pantallas que existen sin la app instalada: la bienvenida y el aviso de "Muy pronto" (con la app
 * apagada no hay nada que instalar).
 */
const SIN_INSTALAR = new Set(['bienvenida', 'no-disponible'])

/** ¿A qué pantalla hay que mandar al cliente, según si la app está instalada? `null` = a la que pidió. */
export function destinoPorInstalacion(
  pantalla: string,
  instalada: boolean,
): 'bienvenida' | 'registro' | null {
  if (!instalada && !SIN_INSTALAR.has(pantalla)) return 'bienvenida'
  if (instalada && pantalla === 'bienvenida') return 'registro'
  return null
}

export type Plataforma = 'iphone' | 'android' | 'otra'

export function plataformaDe(userAgent: string, maxTouchPoints = 0): Plataforma {
  if (/iphone|ipad|ipod/i.test(userAgent)) return 'iphone'
  // iPadOS se presenta como Mac, pero con pantalla táctil.
  if (/macintosh/i.test(userAgent) && maxTouchPoints > 1) return 'iphone'
  if (/android/i.test(userAgent)) return 'android'
  return 'otra'
}
