<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import mapService from '@/services/maps/MapService'
import http from '@/lib/http'
import { useTenantAuthStore } from '@/stores/tenantAuth'
import realtimeService from '@/services/realtime'

const CONTAINER_ID = 'mapa-conductores'
const route = useRoute()
const slug = route.params.slug as string
const tenantAuth = useTenantAuthStore()

interface PedidoAsignado {
  id_pedido: number
  estado: 'TOMADO' | 'ARRIBADO' | 'EN_CAMINO' | 'ARRIBADO_A_ENTREGA'
  latitud_recogida: number
  longitud_recogida: number
  latitud_entrega: number
  longitud_entrega: number
}

interface ConductorActivo {
  id_conductor: number
  nombre: string
  disponibilidad: string
  es_prueba: boolean
  latitud: number | null
  longitud: number | null
  pedido_asignado: PedidoAsignado | null
}

let conductores: ConductorActivo[] = []

async function cargarYDibujar() {
  try {
    const { data } = await http.get(`/t/${slug}/conductores/activos`)
    conductores = data.data as ConductorActivo[]
  } catch {
    return
  }

  mapService.clearMarkers(CONTAINER_ID)
  mapService.clearRoutes(CONTAINER_ID)

  for (const conductor of conductores) {
    if (conductor.latitud === null || conductor.longitud === null) continue

    const posicion = { lat: conductor.latitud, lng: conductor.longitud }
    mapService.addMarker(CONTAINER_ID, String(conductor.id_conductor), posicion, {
      title: conductor.es_prueba ? `${conductor.nombre} (prueba)` : conductor.nombre,
    })

    const pedido = conductor.pedido_asignado
    if (!pedido) continue

    const destino =
      pedido.estado === 'TOMADO' || pedido.estado === 'ARRIBADO'
        ? { lat: pedido.latitud_recogida, lng: pedido.longitud_recogida }
        : { lat: pedido.latitud_entrega, lng: pedido.longitud_entrega }

    mapService.drawRoute(CONTAINER_ID, `ruta-${conductor.id_conductor}`, [posicion, destino], {
      preserveViewport: true,
    })
  }
}

/**
 * Mueve el marcador sin volver a pedir la lista completa (spec tenant/021): es el evento de mayor
 * frecuencia del sistema, refrescar todo en cada punto sería carísimo y innecesario — la posición
 * es lo único que cambió.
 */
function onUbicacionActualizada(payload: {
  id_conductor: number
  latitud: number
  longitud: number
}) {
  const conductor = conductores.find((c) => c.id_conductor === payload.id_conductor)
  if (!conductor) return

  conductor.latitud = payload.latitud
  conductor.longitud = payload.longitud
  mapService.updateMarker(CONTAINER_ID, String(payload.id_conductor), {
    lat: payload.latitud,
    lng: payload.longitud,
  })
}

onMounted(async () => {
  if (!mapService.hasApiKey()) return

  await mapService.initialize(CONTAINER_ID, { zoom: 12 })

  const ciudades = tenantAuth.usuario?.ciudades_tenant ?? []
  if (ciudades.length > 0) {
    mapService.fitToPositions(
      CONTAINER_ID,
      ciudades.map((ciudad) => ({ lat: ciudad.lat, lng: ciudad.lng, bounds: ciudad.bounds })),
    )
  }

  await cargarYDibujar()

  const channel = realtimeService.subscribe(slug)
  channel?.bind('ubicacion.actualizada', onUbicacionActualizada)
  // Quién está en línea, o el pedido que trae asignado, cambió: la ruta dibujada ya no aplica y
  // toca redibujar todo (evento poco frecuente comparado con la posición).
  channel?.bind('conductor.disponibilidad-cambiada', cargarYDibujar)
  channel?.bind('pedido.tomado', cargarYDibujar)
  channel?.bind('pedido.cancelado', cargarYDibujar)
  channel?.bind('pedido.entregado', cargarYDibujar)
})

onBeforeUnmount(() => {
  const channel = realtimeService.subscribe(slug)
  channel?.unbind('ubicacion.actualizada', onUbicacionActualizada)
  channel?.unbind('conductor.disponibilidad-cambiada', cargarYDibujar)
  channel?.unbind('pedido.tomado', cargarYDibujar)
  channel?.unbind('pedido.cancelado', cargarYDibujar)
  channel?.unbind('pedido.entregado', cargarYDibujar)
  mapService.destroy(CONTAINER_ID)
})

/**
 * spec tenant/023: el mapa es el fondo de todo el Panel y el panel de flotilla se colapsa encima de
 * él. Google no se entera solo de que su contenedor cambió de ancho, así que `PanelView` llama a
 * esto al terminar la animación.
 */
defineExpose({
  redimensionar: () => mapService.resize(CONTAINER_ID),
})
</script>

<template>
  <div
    v-if="mapService.hasApiKey()"
    :id="CONTAINER_ID"
    class="h-full w-full"
    data-testid="mapa-conductores"
  />
  <p v-else class="p-5 text-sm text-black/50">
    Configura la clave de Google Maps para ver el mapa.
  </p>
</template>
