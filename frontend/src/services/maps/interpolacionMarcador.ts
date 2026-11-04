import type { LatLngLike } from './types'

/**
 * Movimiento fluido del marcador (spec tenant/028, §19). Sin dependencias de Google: solo decide
 * desde dónde, hacia dónde y durante cuánto se desliza un marcador entre dos posiciones.
 */

/** Por debajo de esto la animación ya no se percibe como movimiento continuo. */
export const DURACION_MINIMA_MS = 500

/** Tope: con posiciones muy espaciadas es mejor llegar y esperar que arrastrarse minutos. */
export const DURACION_MAXIMA_MS = 4000

/** La primera posición después de crear el marcador todavía no tiene un intervalo que medir. */
export const DURACION_INICIAL_MS = 1000

/** Más lejos que esto no es movimiento, es reconexión o GPS corregido: se salta sin animar. */
export const SALTO_MAXIMO_METROS = 500

/** Tras esta pausa sin posiciones nuevas, deslizarse aparentaría un recorrido que nadie vio. */
export const PAUSA_MAXIMA_MS = 30_000

const METROS_POR_GRADO = 111_320

export interface TramoMarcador {
  desde: LatLngLike
  inicio: number
  duracion: number
}

export interface MovimientoMarcador {
  destino: LatLngLike
  /** Cuándo cambió el destino por última vez. Sirve para medir el intervalo entre posiciones. */
  recibidoEn: number | null
  /** Tramo en curso, o `null` si el marcador ya está en su destino. */
  tramo: TramoMarcador | null
}

/** A estas distancias basta aproximar el terreno como un plano, igual que `recorteRuta`. */
function metrosEntre(a: LatLngLike, b: LatLngLike): number {
  const metrosPorGradoLng = METROS_POR_GRADO * Math.cos((a.lat * Math.PI) / 180)

  return Math.hypot((b.lng - a.lng) * metrosPorGradoLng, (b.lat - a.lat) * METROS_POR_GRADO)
}

/** Estado de un marcador recién creado: ya está en su destino. */
export function iniciarMovimiento(destino: LatLngLike): MovimientoMarcador {
  return { destino, recibidoEn: null, tramo: null }
}

/**
 * Siguiente estado del marcador, o `null` si el destino no cambió. Eso es lo normal: el mapa
 * sincroniza a todos los conductores cada vez que llega la posición de uno, y reiniciar aquí haría
 * que ningún marcador terminara de deslizarse.
 *
 * Arranca desde `dibujada`, no desde el destino anterior: si la posición nueva llega a media
 * animación, el marcador sigue desde donde se ve en lugar de brincar hacia atrás. La duración es el
 * intervalo observado entre posiciones, para que llegue más o menos cuando salga la siguiente.
 *
 * Un `tramo` nulo en el resultado significa que se debe colocar en el destino sin animar.
 */
export function planearMovimiento(
  actual: MovimientoMarcador,
  dibujada: LatLngLike,
  destino: LatLngLike,
  ahora: number,
  animar = true,
): MovimientoMarcador | null {
  if (actual.destino.lat === destino.lat && actual.destino.lng === destino.lng) {
    return null
  }

  const intervalo = actual.recibidoEn === null ? null : ahora - actual.recibidoEn
  const debeSaltar =
    !animar ||
    (intervalo !== null && intervalo > PAUSA_MAXIMA_MS) ||
    metrosEntre(dibujada, destino) > SALTO_MAXIMO_METROS

  if (debeSaltar) {
    return { destino, recibidoEn: ahora, tramo: null }
  }

  return {
    destino,
    recibidoEn: ahora,
    tramo: {
      desde: dibujada,
      inicio: ahora,
      duracion:
        intervalo === null
          ? DURACION_INICIAL_MS
          : Math.min(DURACION_MAXIMA_MS, Math.max(DURACION_MINIMA_MS, intervalo)),
    },
  }
}

/** Dónde debe verse el marcador ahora mismo. La velocidad es constante, como la de una moto. */
export function posicionEnTramo(
  destino: LatLngLike,
  tramo: TramoMarcador,
  ahora: number,
): { posicion: LatLngLike; terminado: boolean } {
  const fraccion = (ahora - tramo.inicio) / tramo.duracion

  if (fraccion >= 1) {
    return { posicion: destino, terminado: true }
  }

  const avance = Math.max(0, fraccion)

  return {
    posicion: {
      lat: tramo.desde.lat + (destino.lat - tramo.desde.lat) * avance,
      lng: tramo.desde.lng + (destino.lng - tramo.desde.lng) * avance,
    },
    terminado: false,
  }
}
