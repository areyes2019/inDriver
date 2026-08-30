import GoogleProvider from './GoogleProvider'
import type {
  AddressSuggestion,
  LatLngLike,
  MapInitOptions,
  MarkerOptions,
  ResolvedAddress,
  RouteOptions,
  RouteResult,
} from './types'

/**
 * Único punto de acceso a Google Maps para toda la app (patrón Facade + Singleton). Ningún
 * componente Vue debe importar `GoogleProvider` directamente — todos pasan por aquí, para que
 * cambiar de proveedor de mapas en el futuro no requiera tocar los componentes.
 */
class MapService {
  private provider = new GoogleProvider()

  hasApiKey(): boolean {
    return Boolean(import.meta.env.VITE_GOOGLE_MAPS_API_KEY)
  }

  initialize(containerId: string, options: MapInitOptions = {}): Promise<void> {
    return this.provider.initialize(containerId, options)
  }

  addMarker(
    containerId: string,
    markerId: string,
    position: LatLngLike,
    options: MarkerOptions = {},
  ): void {
    this.provider.addMarker(containerId, markerId, position, options)
  }

  updateMarker(containerId: string, markerId: string, position: LatLngLike): void {
    this.provider.updateMarker(containerId, markerId, position)
  }

  clearMarkers(containerId: string): void {
    this.provider.clearMarkers(containerId)
  }

  drawRoute(
    containerId: string,
    routeId: string,
    points: LatLngLike[],
    options: RouteOptions = {},
  ): Promise<RouteResult | null> {
    return this.provider.drawRoute(containerId, routeId, points, options)
  }

  clearRoutes(containerId: string): void {
    this.provider.clearRoutes(containerId)
  }

  centerOn(containerId: string, position: LatLngLike, zoom?: number): void {
    this.provider.centerOn(containerId, position, zoom)
  }

  searchAddress(query: string): Promise<AddressSuggestion[]> {
    return this.provider.searchAddress(query)
  }

  resolveAddress(suggestionId: string): Promise<ResolvedAddress | null> {
    return this.provider.resolveAddress(suggestionId)
  }

  destroy(containerId: string): void {
    this.provider.destroy(containerId)
  }
}

export default new MapService()
