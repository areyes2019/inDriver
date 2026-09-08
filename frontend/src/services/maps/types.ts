export interface LatLngLike {
  lat: number
  lng: number
}

export interface MapInitOptions {
  center?: LatLngLike
  zoom?: number
}

export interface MarkerOptions {
  icon?: string
  title?: string
  /** Color del conductor (spec tenant/026, RN-18): el marcador va del mismo color que su línea. */
  color?: string
}

/** Trazo de la polilínea (spec tenant/026, RN-14/RN-15): guiones camino a la recogida, sólido camino a la entrega. */
export type EstiloRuta = 'GUIONES' | 'SOLIDO'

export interface RouteOptions {
  color?: string
  /** Default: `SOLIDO`. */
  estilo?: EstiloRuta
  /** Si es true, dibujar la ruta no mueve el centro/zoom del mapa (default: false). */
  preserveViewport?: boolean
}

export interface RouteResult {
  distance: string
  duration: string
  distanceKm: number
  /** Puntos de la ruta tal como los devolvió el proveedor (Directions), para animar sobre ellos. */
  path: LatLngLike[]
}

export interface AddressSuggestion {
  id: string
  label: string
}

export interface ResolvedAddress {
  address: string
  lat: number
  lng: number
}

export interface LatLngBoundsLike {
  north: number
  south: number
  east: number
  west: number
}

export interface ResolvedCity {
  nombre: string
  lat: number
  lng: number
  bounds: LatLngBoundsLike | null
}

export interface FitTarget {
  lat: number
  lng: number
  bounds?: LatLngBoundsLike | null
}

export interface PolygonDrawOptions {
  /** Vértices iniciales para editar un polígono existente; sin ellos, arranca el modo de dibujo. */
  initialPoints?: LatLngLike[]
  /** Se dispara con el arreglo de vértices vigente cada vez que el polígono cambia. */
  onChange: (points: LatLngLike[]) => void
}
