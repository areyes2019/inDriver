<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import mapService from '@/services/maps/MapService'
import type { AddressSuggestion, ResolvedCity } from '@/services/maps/types'

withDefaults(
  defineProps<{
    placeholder?: string
  }>(),
  { placeholder: 'Buscar ciudad...' },
)

const emit = defineEmits<{
  select: [ciudad: ResolvedCity & { placeId: string }]
}>()

const texto = ref('')
const sugerencias = ref<AddressSuggestion[]>([])
const mostrarLista = ref(false)
let timeoutId: ReturnType<typeof setTimeout> | undefined

function onInput(event: Event) {
  texto.value = (event.target as HTMLInputElement).value

  clearTimeout(timeoutId)
  if (!mapService.hasApiKey() || texto.value.trim().length < 3) {
    sugerencias.value = []
    mostrarLista.value = false
    return
  }

  timeoutId = setTimeout(async () => {
    sugerencias.value = await mapService.searchCity(texto.value)
    mostrarLista.value = sugerencias.value.length > 0
  }, 300)
}

async function seleccionar(sugerencia: AddressSuggestion) {
  mostrarLista.value = false
  sugerencias.value = []

  const resuelta = await mapService.resolveCity(sugerencia.id)
  if (!resuelta) return

  emit('select', { ...resuelta, placeId: sugerencia.id })
  texto.value = ''
}

onBeforeUnmount(() => clearTimeout(timeoutId))
</script>

<template>
  <div class="relative">
    <input
      :value="texto"
      type="text"
      :placeholder="placeholder"
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
