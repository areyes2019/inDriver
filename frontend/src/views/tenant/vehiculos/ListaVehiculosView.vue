<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'

interface Vehiculo {
  id_vehiculo: number
  placa: string
  marca: string | null
  modelo: string | null
  anio: number | null
  color: string | null
  tipo: string | null
  numero_economico: string | null
  estado: string
  created_at: string
}

const route = useRoute()
const slug = computed(() => route.params.slug as string)

const vehiculos = ref<Vehiculo[]>([])
const search = ref('')
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')

const estadoColor: Record<string, 'green' | 'orange' | 'blue'> = {
  ACTIVO: 'green',
  INACTIVO: 'blue',
  MANTENIMIENTO: 'orange',
}

async function fetchVehiculos() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await http.get(`/t/${slug.value}/vehiculos`, {
      params: { search: search.value || undefined, page: page.value },
    })
    vehiculos.value = data.data
    lastPage.value = data.meta?.last_page ?? 1
  } catch {
    error.value = 'No se pudo cargar la lista de vehículos.'
  } finally {
    loading.value = false
  }
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined

watch(search, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    page.value = 1
    fetchVehiculos()
  }, 300)
})

watch(page, () => fetchVehiculos())

onMounted(fetchVehiculos)
</script>

<template>
  <TenantLayout>
    <UiCard title="Vehículos">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <input
          v-model="search"
          type="search"
          placeholder="Buscar por placa, marca, modelo o número económico..."
          class="w-full max-w-sm rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
        />
        <RouterLink
          :to="{ name: 'tenant-vehiculos-crear', params: { slug } }"
          class="rounded-lg bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-heading"
        >
          Nuevo vehículo
        </RouterLink>
      </div>

      <p v-if="error" role="alert" class="mb-4 text-sm text-red-600">{{ error }}</p>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-left text-sm">
          <thead>
            <tr
              class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
            >
              <th class="py-2 pr-4">Placa</th>
              <th class="py-2 pr-4">Marca / Modelo</th>
              <th class="py-2 pr-4">Número económico</th>
              <th class="py-2 pr-4">Estado</th>
              <th class="py-2 pr-4">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="5" class="py-6 text-center text-black/50">Cargando...</td>
            </tr>
            <tr v-else-if="vehiculos.length === 0">
              <td colspan="5" class="py-6 text-center text-black/50">No hay vehículos.</td>
            </tr>
            <tr
              v-for="vehiculo in vehiculos"
              v-else
              :key="vehiculo.id_vehiculo"
              class="border-b border-gray-100 text-heading"
            >
              <td class="py-2 pr-4 font-medium">{{ vehiculo.placa }}</td>
              <td class="py-2 pr-4">
                {{ [vehiculo.marca, vehiculo.modelo].filter(Boolean).join(' ') || '—' }}
              </td>
              <td class="py-2 pr-4">{{ vehiculo.numero_economico ?? '—' }}</td>
              <td class="py-2 pr-4">
                <UiBadge :text="vehiculo.estado" :color="estadoColor[vehiculo.estado] ?? 'blue'" />
              </td>
              <td class="py-2 pr-4">
                <RouterLink
                  :to="{
                    name: 'tenant-vehiculos-editar',
                    params: { slug, id: vehiculo.id_vehiculo },
                  }"
                  class="rounded-lg bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-heading"
                >
                  Editar
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="lastPage > 1" class="mt-4 flex items-center gap-3">
        <button
          type="button"
          :disabled="page <= 1"
          class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-heading disabled:cursor-not-allowed disabled:opacity-50"
          @click="page -= 1"
        >
          Anterior
        </button>
        <span class="text-sm text-black/60">Página {{ page }} de {{ lastPage }}</span>
        <button
          type="button"
          :disabled="page >= lastPage"
          class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-heading disabled:cursor-not-allowed disabled:opacity-50"
          @click="page += 1"
        >
          Siguiente
        </button>
      </div>
    </UiCard>
  </TenantLayout>
</template>
