<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { onBeforeUnmount, onMounted, ref } from 'vue'
import MapService from '@/services/maps/MapService'
import type { Coordenada } from '../api/tipos'

/**
 * Contenedor del mapa de Google, con el mismo servicio que usa el Panel. Si no hay llave o Google no
 * carga, muestra un respaldo y avisa con `listo(false)` para que la pantalla siga funcionando.
 */
const props = withDefaults(defineProps<{ id: string; centro: Coordenada; zoom?: number }>(), {
  zoom: 14,
})
const emit = defineEmits<{ listo: [ok: boolean] }>()
const fallo = ref(false)

onMounted(async () => {
  if (!MapService.hasApiKey()) {
    fallo.value = true
    emit('listo', false)
    return
  }
  try {
    await MapService.initialize(props.id, { center: props.centro, zoom: props.zoom })
    emit('listo', true)
  } catch {
    fallo.value = true
    emit('listo', false)
  }
})

onBeforeUnmount(() => MapService.destroy(props.id))
</script>

<template>
  <div class="relative size-full overflow-hidden bg-morado-50">
    <div :id="id" class="size-full" />
    <div
      v-if="fallo"
      class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-[radial-gradient(circle_at_30%_30%,#ede9fe,#f7f6fb)] text-center text-sm text-suave"
    >
      <Icon icon="tabler:map-pin" class="size-8 text-morado-500" />
      El mapa no está disponible en este momento
    </div>
  </div>
</template>
