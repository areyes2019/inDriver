import type {
  AddressSuggestion,
  FitTarget,
  LatLngLike,
  MapInitOptions,
  MarkerOptions,
  ResolvedAddress,
  ResolvedCity,
  RouteOptions,
  RouteResult,
} from './types'

/**
 * Contrato que todo proveedor de mapas debe cumplir (patrón Strategy). Cada mapa se identifica
 * por el `containerId` de su `<div>`, para poder tener varios mapas activos a la vez (ej. el mapa
 * de conductores y una vista previa de ruta dentro de un formulario, al mismo tiempo).
 *
 * Como JavaScript no tiene interfaces nativas, cada método lanza un error si un proveedor no lo
 * sobrescribe (fail-fast).
 */
export default class BaseProvider {
  async initialize(_containerId: string, _options: MapInitOptions = {}): Promise<void> {
    throw new Error('Method "initialize" must be implemented')
  }

  addMarker(
    _containerId: string,
    _markerId: string,
    _position: LatLngLike,
    _options: MarkerOptions = {},
  ): void {
    throw new Error('Method "addMarker" must be implemented')
  }

  updateMarker(_containerId: string, _markerId: string, _position: LatLngLike): void {
    throw new Error('Method "updateMarker" must be implemented')
  }

  clearMarkers(_containerId: string): void {
    throw new Error('Method "clearMarkers" must be implemented')
  }

  async drawRoute(
    _containerId: string,
    _routeId: string,
    _points: LatLngLike[],
    _options: RouteOptions = {},
  ): Promise<RouteResult | null> {
    throw new Error('Method "drawRoute" must be implemented')
  }

  clearRoutes(_containerId: string): void {
    throw new Error('Method "clearRoutes" must be implemented')
  }

  centerOn(_containerId: string, _position: LatLngLike, _zoom = 13): void {
    throw new Error('Method "centerOn" must be implemented')
  }

  async searchAddress(_query: string): Promise<AddressSuggestion[]> {
    throw new Error('Method "searchAddress" must be implemented')
  }

  async resolveAddress(_suggestionId: string): Promise<ResolvedAddress | null> {
    throw new Error('Method "resolveAddress" must be implemented')
  }

  async searchCity(_query: string): Promise<AddressSuggestion[]> {
    throw new Error('Method "searchCity" must be implemented')
  }

  async resolveCity(_suggestionId: string): Promise<ResolvedCity | null> {
    throw new Error('Method "resolveCity" must be implemented')
  }

  fitToPositions(_containerId: string, _targets: FitTarget[]): void {
    throw new Error('Method "fitToPositions" must be implemented')
  }

  destroy(_containerId: string): void {
    throw new Error('Method "destroy" must be implemented')
  }
}
