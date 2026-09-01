<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import mapService from '@/services/maps/MapService'
import type { AddressSuggestion, LatLngBoundsLike } from '@/services/maps/types'

const props = withDefaults(
  defineProps<{
    modelValue: string
    placeholder?: string
    required?: boolean
    /** Área de servicio del tenant — acota las sugerencias de Google a este rectángulo. */
    bounds?: LatLngBoundsLike | null
  }>(),
  { placeholder: undefined, required: false, bounds: null },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
  select: [payload: { lat: number | null; lng: number | null }]
}>()

const sugerencias = ref<AddressSuggestion[]>([])
const mostrarLista = ref(false)
let timeoutId: ReturnType<typeof setTimeout> | undefined

function onInput(event: Event) {
  const value = (event.target as HTMLInputElement).value
  emit('update:modelValue', value)

  clearTimeout(timeoutId)
  if (!mapService.hasApiKey() || value.trim().length < 3) {
    sugerencias.value = []
    mostrarLista.value = false
    return
  }

  timeoutId = setTimeout(async () => {
    sugerencias.value = await mapService.searchAddress(value, props.bounds)
    mostrarLista.value = sugerencias.value.length > 0
  }, 300)
}

async function seleccionar(sugerencia: AddressSuggestion) {
  mostrarLista.value = false
  sugerencias.value = []

  const resuelta = await mapService.resolveAddress(sugerencia.id)
  emit('update:modelValue', resuelta?.address ?? sugerencia.label)
  emit('select', { lat: resuelta?.lat ?? null, lng: resuelta?.lng ?? null })
}

onBeforeUnmount(() => clearTimeout(timeoutId))
</script>

<template>
  <div class="relative">
    <input
      :value="modelValue"
      type="text"
      :placeholder="placeholder"
      :required="required"
      autocomplete="off"
      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
      @input="onInput"
      @focus="mostrarLista = sugerencias.length > 0"
      @blur="mostrarLista = false"
    />
    <ul
      v-if="mostrarLista"
      class="absolute z-10 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg"
    >
      <li
        v-for="sugerencia in sugerencias"
        :key="sugerencia.id"
        class="cursor-pointer px-3 py-2 text-sm text-heading hover:bg-accent/10"
        @mousedown.prevent="seleccionar(sugerencia)"
      >
        {{ sugerencia.label }}
      </li>
    </ul>
  </div>
</template>
