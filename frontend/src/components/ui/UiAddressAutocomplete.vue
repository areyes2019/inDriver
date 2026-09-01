<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import mapService from '@/services/maps/MapService'
import type { AddressSuggestion, LatLngBoundsLike } from '@/services/maps/types'

const props = withDefaults(
  defineProps<{
    modelValue: string
    placeholder?: string
    required?: boolean
    /** Área de servicio del tenant — acota las sugerencias de Google a este rectángulo. */
    bounds?: LatLngBoundsLike | null
    /** Muestra un ícono de check cuando la dirección quedó resuelta contra Google Maps. */
    mostrarIndicador?: boolean
    /** Marca la dirección como ya resuelta al montar (p. ej. autocompletada desde un cliente). */
    resuelta?: boolean
  }>(),
  {
    placeholder: undefined,
    required: false,
    bounds: null,
    mostrarIndicador: false,
    resuelta: false,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
  select: [payload: { lat: number | null; lng: number | null }]
}>()

const sugerencias = ref<AddressSuggestion[]>([])
const mostrarLista = ref(false)
const direccionResuelta = ref(props.resuelta)
let timeoutId: ReturnType<typeof setTimeout> | undefined

watch(
  () => props.resuelta,
  (valor) => {
    direccionResuelta.value = valor
  },
)

function onInput(event: Event) {
  const value = (event.target as HTMLInputElement).value
  emit('update:modelValue', value)
  direccionResuelta.value = false

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
  direccionResuelta.value = resuelta !== null
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
      :class="mostrarIndicador && direccionResuelta ? 'pr-9' : ''"
      @input="onInput"
      @focus="mostrarLista = sugerencias.length > 0"
      @blur="mostrarLista = false"
    />
    <Icon
      v-if="mostrarIndicador && direccionResuelta"
      icon="flat-color-icons:checkmark"
      width="18"
      height="18"
      class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2"
      aria-label="Ubicación encontrada"
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
