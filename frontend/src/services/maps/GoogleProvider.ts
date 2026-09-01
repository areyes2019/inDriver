import { importLibrary, setOptions } from '@googlemaps/js-api-loader'
import BaseProvider from './BaseProvider'
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

interface MapInstance {
  map: google.maps.Map
  markers: Map<string, google.maps.Marker>
  routes: Map<string, google.maps.DirectionsRenderer | google.maps.Polyline>
  directionsService: google.maps.DirectionsService
}

declare global {
  interface Window {
    gm_authFailure?: () => void
  }
}

// Ciudad de México — centro ficticio de respaldo, usado hasta que exista una ubicación real
// configurable por tenant.
const DEFAULT_CENTER: LatLngLike = { lat: 19.4326, lng: -99.1332 }

let loaderPromise: Promise<void> | null = null

/** Carga el SDK de Google Maps una sola vez, sin importar cuántos mapas se inicialicen. */
function loadSdk(apiKey: string): Promise<void> {
  if (!loaderPromise) {
    // Evita que el error de autenticación de Google muestre un alert bloqueante; en su lugar
    // deja un aviso en consola con instrucciones.
    window.gm_authFailure = () => {
      console.warn(
        '⚠️ Google Maps: error de autenticación. Verifica VITE_GOOGLE_MAPS_API_KEY y el referente autorizado en Google Cloud Console.',
      )
    }

    setOptions({ key: apiKey })
    // `maps` trae Map/Marker/Polyline, `places` el autocompletado y `routes` Directions.
    loaderPromise = Promise.all([
      importLibrary('maps'),
      importLibrary('places'),
      importLibrary('routes'),
    ]).then(() => undefined)
  }
  return loaderPromise
}

export default class GoogleProvider extends BaseProvider {
  private apiKey = import.meta.env.VITE_GOOGLE_MAPS_API_KEY as string | undefined
  private instances = new Map<string, MapInstance>()
  private suggestionCache = new Map<string, google.maps.places.PlacePrediction>()

  async initialize(containerId: string, options: MapInitOptions = {}): Promise<void> {
    if (!this.apiKey) {
      throw new Error('VITE_GOOGLE_MAPS_API_KEY no está configurada.')
    }

    await loadSdk(this.apiKey)

    const el = document.getElementById(containerId)
    if (!el) {
      throw new Error(`Contenedor #${containerId} no encontrado.`)
    }

    const existing = this.instances.get(containerId)
    if (existing && existing.map.getDiv() === el) {
      return
    }
    if (existing) {
      this.destroy(containerId)
    }

    const map = new google.maps.Map(el, {
      center: options.center ?? DEFAULT_CENTER,
      zoom: options.zoom ?? 12,
      disableDefaultUI: true,
      zoomControl: true,
    })

    this.instances.set(containerId, {
      map,
      markers: new Map(),
      routes: new Map(),
      directionsService: new google.maps.DirectionsService(),
    })
  }

  addMarker(
    containerId: string,
    markerId: string,
    position: LatLngLike,
    options: MarkerOptions = {},
  ): void {
    const instance = this.instances.get(containerId)
    if (!instance) return

    instance.markers.get(markerId)?.setMap(null)

    const marker = new google.maps.Marker({
      map: instance.map,
      position,
      title: options.title,
      label: options.icon,
    })
    instance.markers.set(markerId, marker)
  }

  updateMarker(containerId: string, markerId: string, position: LatLngLike): void {
    const instance = this.instances.get(containerId)
    const marker = instance?.markers.get(markerId)
    if (marker) {
      marker.setPosition(position)
    }
  }

  clearMarkers(containerId: string): void {
    const instance = this.instances.get(containerId)
    if (!instance) return
    instance.markers.forEach((marker) => marker.setMap(null))
    instance.markers.clear()
  }

  async drawRoute(
    containerId: string,
    routeId: string,
    points: LatLngLike[],
    options: RouteOptions = {},
  ): Promise<RouteResult | null> {
    const instance = this.instances.get(containerId)
    if (!instance || points.length < 2) return null

    instance.routes.get(routeId)?.setMap(null)

    const origin = points[0] as LatLngLike
    const destination = points[points.length - 1] as LatLngLike
    const color = options.color ?? '#6366F1'

    try {
      const response = await instance.directionsService.route({
        origin,
        destination,
        travelMode: google.maps.TravelMode.DRIVING,
      })

      const renderer = new google.maps.DirectionsRenderer({
        map: instance.map,
        suppressMarkers: true,
        preserveViewport: options.preserveViewport ?? false,
        polylineOptions: { strokeColor: color, strokeWeight: 5 },
      })
      renderer.setDirections(response)
      instance.routes.set(routeId, renderer)

      const leg = response.routes[0]?.legs[0]
      if (!leg?.distance || !leg?.duration) return null

      return { distance: leg.distance.text, duration: leg.duration.text }
    } catch {
      const polyline = new google.maps.Polyline({
        map: instance.map,
        path: points,
        geodesic: true,
        strokeColor: color,
        strokeWeight: 5,
      })
      instance.routes.set(routeId, polyline)
      return null
    }
  }

  clearRoutes(containerId: string): void {
    const instance = this.instances.get(containerId)
    if (!instance) return
    instance.routes.forEach((route) => route.setMap(null))
    instance.routes.clear()
  }

  centerOn(containerId: string, position: LatLngLike, zoom?: number): void {
    const instance = this.instances.get(containerId)
    if (!instance) return
    instance.map.setCenter(position)
    if (zoom !== undefined) {
      instance.map.setZoom(zoom)
    }
  }

  async searchAddress(query: string): Promise<AddressSuggestion[]> {
    if (!this.apiKey || !query.trim()) return []

    await loadSdk(this.apiKey)

    const { suggestions } =
      await google.maps.places.AutocompleteSuggestion.fetchAutocompleteSuggestions({
        input: query,
      })

    this.suggestionCache.clear()
    const results: AddressSuggestion[] = []
    for (const suggestion of suggestions) {
      const prediction = suggestion.placePrediction
      if (!prediction) continue
      this.suggestionCache.set(prediction.placeId, prediction)
      results.push({ id: prediction.placeId, label: prediction.text.text })
    }
    return results
  }

  async resolveAddress(suggestionId: string): Promise<ResolvedAddress | null> {
    const prediction = this.suggestionCache.get(suggestionId)
    if (!prediction) return null

    const place = prediction.toPlace()
    await place.fetchFields({ fields: ['formattedAddress', 'location'] })
    if (!place.location) return null

    return {
      address: place.formattedAddress ?? '',
      lat: place.location.lat(),
      lng: place.location.lng(),
    }
  }

  async searchCity(query: string): Promise<AddressSuggestion[]> {
    if (!this.apiKey || !query.trim()) return []

    await loadSdk(this.apiKey)

    const { suggestions } =
      await google.maps.places.AutocompleteSuggestion.fetchAutocompleteSuggestions({
        input: query,
        includedPrimaryTypes: ['locality'],
      })

    this.suggestionCache.clear()
    const results: AddressSuggestion[] = []
    for (const suggestion of suggestions) {
      const prediction = suggestion.placePrediction
      if (!prediction) continue
      this.suggestionCache.set(prediction.placeId, prediction)
      results.push({ id: prediction.placeId, label: prediction.text.text })
    }
    return results
  }

  async resolveCity(suggestionId: string): Promise<ResolvedCity | null> {
    const prediction = this.suggestionCache.get(suggestionId)
    if (!prediction) return null

    const place = prediction.toPlace()
    await place.fetchFields({ fields: ['displayName', 'location', 'viewport'] })
    if (!place.location) return null

    const viewport = place.viewport

    return {
      nombre: place.displayName ?? prediction.text.text,
      lat: place.location.lat(),
      lng: place.location.lng(),
      bounds: viewport
        ? {
            north: viewport.getNorthEast().lat(),
            east: viewport.getNorthEast().lng(),
            south: viewport.getSouthWest().lat(),
            west: viewport.getSouthWest().lng(),
          }
        : null,
    }
  }

  fitToPositions(containerId: string, targets: FitTarget[]): void {
    const instance = this.instances.get(containerId)
    if (!instance || targets.length === 0) return

    const bounds = new google.maps.LatLngBounds()
    for (const target of targets) {
      if (target.bounds) {
        bounds.extend({ lat: target.bounds.north, lng: target.bounds.east })
        bounds.extend({ lat: target.bounds.south, lng: target.bounds.west })
      } else {
        bounds.extend({ lat: target.lat, lng: target.lng })
      }
    }
    instance.map.fitBounds(bounds)
  }

  destroy(containerId: string): void {
    this.clearMarkers(containerId)
    this.clearRoutes(containerId)
    this.instances.delete(containerId)
  }
}
