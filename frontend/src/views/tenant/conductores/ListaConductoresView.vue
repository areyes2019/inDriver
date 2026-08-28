<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'

interface Conductor {
  id_conductor: number
  id_usuario: number
  nombre: string
  apellido_paterno: string
  email: string
  numero_licencia: string
  tipo_licencia: string | null
  estado: string
  disponibilidad: string
  created_at: string
}

const route = useRoute()
const slug = computed(() => route.params.slug as string)

const conductores = ref<Conductor[]>([])
const search = ref('')
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')

const estadoColor: Record<string, 'green' | 'yellow' | 'blue'> = {
  ACTIVO: 'green',
  INACTIVO: 'blue',
  BLOQUEADO: 'yellow',
}

async function fetchConductores() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await http.get(`/t/${slug.value}/conductores`, {
      params: { search: search.value || undefined, page: page.value },
    })
    conductores.value = data.data
    lastPage.value = data.meta?.last_page ?? 1
  } catch {
    error.value = 'No se pudo cargar la lista de conductores.'
  } finally {
    loading.value = false
  }
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined

watch(search, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    page.value = 1
    fetchConductores()
  }, 300)
})

watch(page, () => fetchConductores())

onMounted(fetchConductores)
</script>

<template>
  <TenantLayout>
    <UiCard title="Conductores">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <input
          v-model="search"
          type="search"
          placeholder="Buscar por nombre, email o licencia..."
          class="w-full max-w-sm rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
        />
        <RouterLink
          :to="{ name: 'tenant-conductores-crear', params: { slug } }"
          class="rounded-lg bg-brand-blue px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark"
        >
          Nuevo conductor
        </RouterLink>
      </div>

      <p v-if="error" role="alert" class="mb-4 text-sm text-red-600">{{ error }}</p>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-left text-sm">
          <thead>
            <tr
              class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
            >
              <th class="py-2 pr-4">Nombre</th>
              <th class="py-2 pr-4">Email</th>
              <th class="py-2 pr-4">Licencia</th>
              <th class="py-2 pr-4">Estado</th>
              <th class="py-2 pr-4">Disponibilidad</th>
              <th class="py-2 pr-4">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="6" class="py-6 text-center text-black/50">Cargando...</td>
            </tr>
            <tr v-else-if="conductores.length === 0">
              <td colspan="6" class="py-6 text-center text-black/50">No hay conductores.</td>
            </tr>
            <tr
              v-for="conductor in conductores"
              v-else
              :key="conductor.id_conductor"
              class="border-b border-gray-100 text-brand-dark"
            >
              <td class="py-2 pr-4 font-medium">
                {{ conductor.nombre }} {{ conductor.apellido_paterno }}
              </td>
              <td class="py-2 pr-4">{{ conductor.email }}</td>
              <td class="py-2 pr-4">{{ conductor.numero_licencia }}</td>
              <td class="py-2 pr-4">
                <UiBadge
                  :text="conductor.estado"
                  :color="estadoColor[conductor.estado] ?? 'blue'"
                />
              </td>
              <td class="py-2 pr-4">{{ conductor.disponibilidad }}</td>
              <td class="py-2 pr-4">
                <RouterLink
                  :to="{
                    name: 'tenant-conductores-editar',
                    params: { slug, id: conductor.id_conductor },
                  }"
                  class="rounded-lg bg-brand-blue px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark"
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
          class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-brand-dark disabled:cursor-not-allowed disabled:opacity-50"
          @click="page -= 1"
        >
          Anterior
        </button>
        <span class="text-sm text-black/60">Página {{ page }} de {{ lastPage }}</span>
        <button
          type="button"
          :disabled="page >= lastPage"
          class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-brand-dark disabled:cursor-not-allowed disabled:opacity-50"
          @click="page += 1"
        >
          Siguiente
        </button>
      </div>
    </UiCard>
  </TenantLayout>
</template>
