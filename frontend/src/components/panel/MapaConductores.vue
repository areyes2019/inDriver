<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import UiCard from '@/components/ui/UiCard.vue'
import mapService from '@/services/maps/MapService'
import { conductoresActivosFixture } from '@/fixtures/panelDespachador'

const CONTAINER_ID = 'mapa-conductores'

onMounted(async () => {
  if (!mapService.hasApiKey()) return

  await mapService.initialize(CONTAINER_ID, { zoom: 12 })

  for (const conductor of conductoresActivosFixture) {
    const posicion = { lat: conductor.latitud, lng: conductor.longitud }
    mapService.addMarker(CONTAINER_ID, String(conductor.id), posicion, { title: conductor.nombre })

    const pedido = conductor.pedidoAsignado
    if (!pedido) continue

    const destino =
      pedido.estado === 'TOMADO' || pedido.estado === 'ARRIBADO'
        ? { lat: pedido.latitud_recogida, lng: pedido.longitud_recogida }
        : { lat: pedido.latitud_entrega, lng: pedido.longitud_entrega }

    mapService.drawRoute(CONTAINER_ID, `ruta-${conductor.id}`, [posicion, destino])
  }
})

onBeforeUnmount(() => {
  mapService.destroy(CONTAINER_ID)
})
</script>

<template>
  <UiCard title="Mapa">
    <div
      v-if="mapService.hasApiKey()"
      :id="CONTAINER_ID"
      class="h-full min-h-[420px] w-full overflow-hidden rounded-lg"
    />
    <p v-else class="text-sm text-black/50">Configura la clave de Google Maps para ver el mapa.</p>
  </UiCard>
</template>
