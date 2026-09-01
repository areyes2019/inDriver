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
}

export interface RouteOptions {
  color?: string
  /** Si es true, dibujar la ruta no mueve el centro/zoom del mapa (default: false). */
  preserveViewport?: boolean
}

export interface RouteResult {
  distance: string
  duration: string
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
