<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import mapService from '@/services/maps/MapService'
import type { LatLngLike, RouteResult } from '@/services/maps/types'

const props = defineProps<{
  origen: LatLngLike | null
  destino: LatLngLike | null
}>()

const emit = defineEmits<{
  distancia: [km: number | null]
}>()

const containerId = `ruta-preview-${useId()}`
const mapReady = ref(false)
const resultado = ref<RouteResult | null>(null)

watch(
  () => [props.origen?.lat, props.origen?.lng, props.destino?.lat, props.destino?.lng],
  async () => {
    if (!mapService.hasApiKey() || !props.origen || !props.destino) {
      resultado.value = null
      emit('distancia', null)
      return
    }

    await nextTick()
    if (!mapReady.value) {
      await mapService.initialize(containerId, { center: props.origen, zoom: 12 })
      mapReady.value = true
    }
    resultado.value = await mapService.drawRoute(containerId, 'preview', [
      props.origen,
      props.destino,
    ])
    emit('distancia', resultado.value?.distanceKm ?? null)
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  if (mapReady.value) {
    mapService.destroy(containerId)
  }
})
</script>

<template>
  <div v-if="mapService.hasApiKey() && origen && destino" class="space-y-2">
    <div :id="containerId" class="h-48 w-full overflow-hidden rounded-lg border border-gray-200" />
    <p v-if="resultado" class="text-sm text-black/60">
      {{ resultado.distance }} · {{ resultado.duration }}
    </p>
  </div>
</template>
