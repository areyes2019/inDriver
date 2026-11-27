<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import MapService from '@/services/maps/MapService'
import type { AddressSuggestion } from '@/services/maps/types'
import { api } from '../api'
import type { Coordenada, Direccion, Punto } from '../api/tipos'
import { useMarcaStore } from '../stores/marca'
import SelectorEnMapa from './SelectorEnMapa.vue'

/**
 * Captura de una dirección (6.3): favoritas como atajos, autocompletado de Google, "Usar mi
 * ubicación" y "Elegir en el mapa". Todo punto pasa por la zona de servicio antes de aceptarse
 * (RN-20).
 *
 * RN-48: espera 300 ms tras la última tecla y al menos 3 letras. Los tokens de sesión de Places
 * llegan con la API real (Fase 4).
 */
const props = withDefaults(
  defineProps<{ etiqueta: string; favoritas?: Direccion[]; conUbicacion?: boolean }>(),
  { favoritas: () => [], conUbicacion: true },
)
const punto = defineModel<Punto | null>({ required: true })

const marca = useMarcaStore()
const texto = ref('')
const sugerencias = ref<AddressSuggestion[]>([])
const buscando = ref(false)
const ocupado = ref(false)
const error = ref<string | null>(null)
const mapaAbierto = ref(false)
let espera: ReturnType<typeof setTimeout> | null = null

const editando = ref(punto.value === null)
watch(punto, (p) => (editando.value = p === null))

const centro = computed<Coordenada>(
  () => punto.value ?? marca.marca?.centro ?? { lat: 20.5235, lng: -100.8157 },
)

/**
 * Restringe las sugerencias al rectángulo de las zonas activas. Sin zona dibujada, ~25 km
 * alrededor del centro.
 */
const limites = computed(() => {
  if (marca.marca?.limites) return marca.marca.limites
  const c = marca.marca?.centro
  if (!c) return null
  return { north: c.lat + 0.25, south: c.lat - 0.25, east: c.lng + 0.25, west: c.lng - 0.25 }
})

watch(texto, (valor) => {
  error.value = null
  if (espera) clearTimeout(espera)
  if (valor.trim().length < 3) {
    sugerencias.value = []
    return
  }
  espera = setTimeout(async () => {
    buscando.value = true
    try {
      sugerencias.value = (
        await MapService.searchAddress(valor, limites.value, { sesion: true })
      ).slice(0, 5)
    } catch {
      sugerencias.value = []
    } finally {
      buscando.value = false
    }
  }, 300)
})
onBeforeUnmount(() => espera && clearTimeout(espera))

async function aceptar(candidato: Punto): Promise<boolean> {
  ocupado.value = true
  try {
    if (!(await api.validarPunto(candidato))) {
      error.value = 'Por ahora no llegamos a esta dirección'
      return false
    }
    punto.value = candidato
    texto.value = ''
    sugerencias.value = []
    error.value = null
    return true
  } finally {
    ocupado.value = false
  }
}

async function elegirSugerencia(s: AddressSuggestion): Promise<void> {
  ocupado.value = true
  try {
    const resuelta = await MapService.resolveAddress(s.id)
    if (!resuelta) {
      error.value = 'No pudimos ubicar esa dirección. Prueba con otra o elígela en el mapa.'
      return
    }
    await aceptar({ direccion: resuelta.address, lat: resuelta.lat, lng: resuelta.lng })
  } finally {
    ocupado.value = false
  }
}

function usarUbicacion(): void {
  if (!('geolocation' in navigator)) {
    error.value = 'Tu teléfono no comparte la ubicación. Elige el punto en el mapa.'
    return
  }
  ocupado.value = true
  navigator.geolocation.getCurrentPosition(
    async ({ coords }) => {
      const c = { lat: coords.latitude, lng: coords.longitude }
      await aceptar({ ...c, direccion: await api.direccionDe(c) })
      ocupado.value = false
    },
    () => {
      ocupado.value = false
      error.value = 'No pudimos obtener tu ubicación. Elige el punto en el mapa.'
    },
    { enableHighAccuracy: true, timeout: 10_000 },
  )
}

async function desdeMapa(c: Coordenada): Promise<void> {
  ocupado.value = true
  try {
    if (await aceptar({ ...c, direccion: await api.direccionDe(c) })) mapaAbierto.value = false
  } finally {
    ocupado.value = false
  }
}
</script>

<template>
  <div>
    <p class="mb-1.5 text-sm font-semibold text-tinta">{{ props.etiqueta }}</p>

    <div
      v-if="punto && !editando"
      class="flex items-start gap-3 rounded-2xl bg-white p-4 ring-1 ring-linea"
    >
      <Icon icon="tabler:map-pin" class="mt-0.5 size-6 shrink-0 text-morado-700" />
      <p class="flex-1 text-base text-tinta">{{ punto.direccion }}</p>
      <button
        type="button"
        class="-m-2 min-h-11 rounded-xl px-2 text-sm font-semibold text-morado-700"
        @click="editando = true"
      >
        Cambiar
      </button>
    </div>

    <div v-else>
      <div v-if="favoritas.length" class="-mx-5 mb-3 flex gap-2 overflow-x-auto px-5 pb-1">
        <button
          v-for="f in favoritas"
          :key="f.id"
          type="button"
          class="flex min-h-11 shrink-0 items-center gap-1.5 rounded-full bg-morado-50 px-4 text-sm font-semibold text-morado-700 ring-1 ring-morado-100"
          @click="aceptar({ direccion: f.direccion, lat: f.lat, lng: f.lng })"
        >
          <Icon
            :icon="
              f.alias === 'Casa'
                ? 'tabler:home-2'
                : f.alias === 'Trabajo'
                  ? 'tabler:briefcase'
                  : 'tabler:star'
            "
            class="size-4"
          />
          {{ f.alias }}
        </button>
      </div>

      <div
        class="flex items-center rounded-2xl bg-white ring-1 ring-linea focus-within:ring-2 focus-within:ring-morado-500"
      >
        <Icon icon="tabler:search" class="ml-4 size-5 shrink-0 text-suave" aria-hidden="true" />
        <input
          v-model="texto"
          type="search"
          enterkeyhint="search"
          :aria-label="`Buscar ${props.etiqueta.toLowerCase()}`"
          placeholder="Calle y número, colonia o lugar"
          class="min-h-14 w-full bg-transparent px-3 text-base outline-none placeholder:text-suave/60"
        />
        <Icon
          v-if="buscando || ocupado"
          icon="tabler:refresh"
          class="mr-4 size-5 shrink-0 animate-spin text-morado-500"
        />
      </div>

      <ul
        v-if="sugerencias.length"
        class="mt-2 divide-y divide-linea overflow-hidden rounded-2xl bg-white ring-1 ring-linea"
      >
        <li v-for="s in sugerencias" :key="s.id">
          <button
            type="button"
            class="flex min-h-14 w-full items-center gap-3 px-4 py-2 text-left active:bg-morado-50"
            @click="elegirSugerencia(s)"
          >
            <Icon icon="tabler:map-pin" class="size-5 shrink-0 text-suave" />
            <span class="text-sm text-tinta">{{ s.label }}</span>
          </button>
        </li>
      </ul>

      <div class="mt-3 grid gap-2" :class="conUbicacion ? 'grid-cols-2' : 'grid-cols-1'">
        <button
          v-if="conUbicacion"
          type="button"
          class="flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-white text-sm font-semibold text-morado-700 ring-1 ring-linea active:bg-morado-50"
          @click="usarUbicacion"
        >
          <Icon icon="tabler:current-location" class="size-5" /> Mi ubicación
        </button>
        <button
          type="button"
          class="flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-white text-sm font-semibold text-morado-700 ring-1 ring-linea active:bg-morado-50"
          @click="mapaAbierto = true"
        >
          <Icon icon="tabler:focus-centered" class="size-5" /> Elegir en el mapa
        </button>
      </div>
    </div>

    <p v-if="error" class="mt-2 flex items-center gap-1.5 text-sm text-peligro" role="alert">
      <Icon icon="tabler:alert-triangle" class="size-4 shrink-0" /> {{ error }}
    </p>

    <SelectorEnMapa
      v-if="mapaAbierto"
      :centro="centro"
      :titulo="props.etiqueta"
      :ocupado="ocupado"
      @elegir="desdeMapa"
      @cerrar="mapaAbierto = false"
    />
  </div>
</template>
