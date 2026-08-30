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
