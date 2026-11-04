import type { LatLngLike } from './types'

/**
 * Geometría del recorte de la polilínea (spec tenant/026, RN-15 y RN-23 a RN-26). Sin dependencias
 * de Google: solo decide qué parte del trazo sigue por delante del conductor.
 */

/** Hasta dónde va el conductor sobre el trazo: un tramo y la fracción recorrida de ese tramo. */
export interface AvanceRuta {
  tramo: number
  fraccion: number
}

export const AVANCE_INICIAL: AvanceRuta = { tramo: 0, fraccion: 0 }

/**
 * RN-24: margen para considerar que el conductor va sobre el trazo. Es holgado porque el GPS se
 * desvía y `overview_path` viene simplificado, así que corta esquinas respecto de la calle real.
 */
export const TOLERANCIA_RECORTE_METROS = 80

const METROS_POR_GRADO = 111_320

/**
 * Punto del tramo a→b más cercano a `posicion`, sin retroceder de `fraccionMinima`. A estas
 * distancias basta aproximar el terreno como un plano centrado en la posición.
 */
function proyectar(posicion: LatLngLike, a: LatLngLike, b: LatLngLike, fraccionMinima: number) {
  const metrosPorGradoLng = METROS_POR_GRADO * Math.cos((posicion.lat * Math.PI) / 180)
  const ax = (a.lng - posicion.lng) * metrosPorGradoLng
  const ay = (a.lat - posicion.lat) * METROS_POR_GRADO
  const dx = (b.lng - a.lng) * metrosPorGradoLng
  const dy = (b.lat - a.lat) * METROS_POR_GRADO
  const largoCuadrado = dx * dx + dy * dy
  const fraccionCercana = largoCuadrado === 0 ? 0 : -(ax * dx + ay * dy) / largoCuadrado
  const fraccion = Math.min(1, Math.max(fraccionMinima, fraccionCercana))

  return { fraccion, metros: Math.hypot(ax + fraccion * dx, ay + fraccion * dy) }
}

/**
 * Nuevo avance del conductor, o `null` si no hay nada que recortar: va fuera del trazo (RN-24) o no
 * progresó. Busca solo hacia adelante (RN-25), así que un brinco del GPS no hace crecer la línea.
 *
 * Se queda con el punto más cercano de la **primera** racha de tramos dentro de la tolerancia, no
 * con el más cercano de todo el trazo: en una ruta que vuelve a pasar por la misma calle, el global
 * podría saltarse de golpe todo lo que falta entre las dos pasadas.
 */
export function calcularAvance(
  trazo: LatLngLike[],
  avance: AvanceRuta,
  posicion: LatLngLike,
  toleranciaMetros = TOLERANCIA_RECORTE_METROS,
): AvanceRuta | null {
  let mejor: (AvanceRuta & { metros: number }) | null = null

  for (let tramo = avance.tramo; tramo < trazo.length - 1; tramo++) {
    const fraccionMinima = tramo === avance.tramo ? avance.fraccion : 0
    const { fraccion, metros } = proyectar(
      posicion,
      trazo[tramo] as LatLngLike,
      trazo[tramo + 1] as LatLngLike,
      fraccionMinima,
    )

    if (metros > toleranciaMetros) {
      if (mejor) break
      continue
    }

    if (!mejor || metros < mejor.metros) {
      mejor = { tramo, fraccion, metros }
    }
  }

  if (!mejor || (mejor.tramo === avance.tramo && mejor.fraccion === avance.fraccion)) {
    return null
  }

  return { tramo: mejor.tramo, fraccion: mejor.fraccion }
}

/** Lo que falta por recorrer: desde el punto de avance hasta el destino, que nunca se recorta. */
export function trazoRestante(trazo: LatLngLike[], avance: AvanceRuta): LatLngLike[] {
  const a = trazo[avance.tramo] as LatLngLike
  const b = trazo[avance.tramo + 1] as LatLngLike

  return [
    {
      lat: a.lat + (b.lat - a.lat) * avance.fraccion,
      lng: a.lng + (b.lng - a.lng) * avance.fraccion,
    },
    ...trazo.slice(avance.tramo + 1),
  ]
}
