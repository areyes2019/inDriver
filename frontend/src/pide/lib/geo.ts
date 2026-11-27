import type { Coordenada } from '../api/tipos.ts'

const RADIO_TIERRA_M = 6_371_000

export function distanciaM(a: Coordenada, b: Coordenada): number {
  const rad = Math.PI / 180
  const dLat = (b.lat - a.lat) * rad
  const dLng = (b.lng - a.lng) * rad
  const h =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2
  return 2 * RADIO_TIERRA_M * Math.asin(Math.min(1, Math.sqrt(h)))
}

export function largoDeTrazo(trazo: readonly Coordenada[]): number {
  let total = 0
  for (let i = 1; i < trazo.length; i++) {
    total += distanciaM(trazo[i - 1] as Coordenada, trazo[i] as Coordenada)
  }
  return total
}

/**
 * Metros que le faltan al repartidor sobre el trazo: desde el vértice más cercano a su posición
 * hasta el final. Es una aproximación suficiente para el tiempo estimado (RN-45).
 */
export function restanteEnTrazo(trazo: readonly Coordenada[], posicion: Coordenada): number {
  if (trazo.length === 0) return 0
  if (trazo.length === 1) return distanciaM(posicion, trazo[0] as Coordenada)

  let masCercano = 0
  let menor = Infinity
  trazo.forEach((punto, i) => {
    const d = distanciaM(posicion, punto)
    if (d < menor) {
      menor = d
      masCercano = i
    }
  })

  return menor + largoDeTrazo(trazo.slice(masCercano))
}

/**
 * Minutos aproximados de llegada (RN-45): la duración que dio Google para el tramo, proporcional a
 * lo que falta. Nunca baja de 1 mientras no haya llegado.
 */
export function minutosRestantes(
  trazo: readonly Coordenada[],
  posicion: Coordenada,
  minutosDelTramo: number,
): number {
  const total = largoDeTrazo(trazo)
  if (total <= 0) return 0
  const fraccion = Math.min(1, restanteEnTrazo(trazo, posicion) / total)
  return Math.max(1, Math.round(minutosDelTramo * fraccion))
}

/** Punto a cierta fracción (0 a 1) del trazo, medida en distancia. */
export function puntoEnTrazo(trazo: readonly Coordenada[], fraccion: number): Coordenada {
  const primero = trazo[0]
  if (!primero) throw new Error('Trazo vacío')
  if (trazo.length === 1 || fraccion <= 0) return primero
  const ultimo = trazo[trazo.length - 1] as Coordenada
  if (fraccion >= 1) return ultimo

  const objetivo = largoDeTrazo(trazo) * fraccion
  let recorrido = 0
  for (let i = 1; i < trazo.length; i++) {
    const a = trazo[i - 1] as Coordenada
    const b = trazo[i] as Coordenada
    const tramo = distanciaM(a, b)
    if (recorrido + tramo >= objetivo) {
      const t = tramo === 0 ? 0 : (objetivo - recorrido) / tramo
      return { lat: a.lat + (b.lat - a.lat) * t, lng: a.lng + (b.lng - a.lng) * t }
    }
    recorrido += tramo
  }
  return ultimo
}
