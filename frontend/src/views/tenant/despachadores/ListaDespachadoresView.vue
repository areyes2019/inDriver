<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'

interface Despachador {
  id_despachador: number
  id_usuario: number
  nombre: string
  apellido_paterno: string
  email: string
  estado: string
  created_at: string
}

const ESTADOS = ['Activo', 'Suspendido', 'Inactivo'] as const

const route = useRoute()
const slug = computed(() => route.params.slug as string)

const despachadores = ref<Despachador[]>([])
const search = ref('')
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')
const savingId = ref<number | null>(null)

const estadoColor: Record<string, 'green' | 'orange' | 'blue'> = {
  Activo: 'green',
  Suspendido: 'orange',
  Inactivo: 'blue',
}

async function fetchDespachadores() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await http.get(`/t/${slug.value}/despachadores`, {
      params: { search: search.value || undefined, page: page.value },
    })
    despachadores.value = data.data
    lastPage.value = data.meta?.last_page ?? 1
  } catch {
    error.value = 'No se pudo cargar la lista de despachadores.'
  } finally {
    loading.value = false
  }
}

async function cambiarEstado(despachador: Despachador, estado: string) {
  if (estado === despachador.estado) return

  savingId.value = despachador.id_despachador
  try {
    const { data } = await http.patch(
      `/t/${slug.value}/despachadores/${despachador.id_despachador}/estado`,
      { estado },
    )
    const updated = data.data ?? data
    const index = despachadores.value.findIndex(
      (d) => d.id_despachador === despachador.id_despachador,
    )
    if (index !== -1) despachadores.value[index] = updated
  } catch {
    error.value = 'No se pudo cambiar el estado del despachador.'
  } finally {
    savingId.value = null
  }
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined

watch(search, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    page.value = 1
    fetchDespachadores()
  }, 300)
})

watch(page, () => fetchDespachadores())

onMounted(fetchDespachadores)
</script>

<template>
  <TenantLayout>
    <UiCard title="Despachadores">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <input
          v-model="search"
          type="search"
          placeholder="Buscar por nombre o email..."
          class="w-full max-w-sm rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
        />
      </div>

      <p v-if="error" role="alert" class="mb-4 text-sm text-red-600">{{ error }}</p>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-left text-sm">
          <thead>
            <tr
              class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
            >
              <th class="py-2 pr-4">Nombre</th>
              <th class="py-2 pr-4">Email</th>
              <th class="py-2 pr-4">Estado</th>
              <th class="py-2 pr-4">Cambiar estado</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="4" class="py-6 text-center text-black/50">Cargando...</td>
            </tr>
            <tr v-else-if="despachadores.length === 0">
              <td colspan="4" class="py-6 text-center text-black/50">No hay despachadores.</td>
            </tr>
            <tr
              v-for="despachador in despachadores"
              v-else
              :key="despachador.id_despachador"
              class="border-b border-gray-100 text-heading"
            >
              <td class="py-2 pr-4 font-medium">
                {{ despachador.nombre }} {{ despachador.apellido_paterno }}
              </td>
              <td class="py-2 pr-4">{{ despachador.email }}</td>
              <td class="py-2 pr-4">
                <UiBadge
                  :text="despachador.estado"
                  :color="estadoColor[despachador.estado] ?? 'blue'"
                />
              </td>
              <td class="py-2 pr-4">
                <select
                  :value="despachador.estado"
                  :disabled="savingId === despachador.id_despachador"
                  class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                  @change="cambiarEstado(despachador, ($event.target as HTMLSelectElement).value)"
                >
                  <option v-for="estado in ESTADOS" :key="estado" :value="estado">
                    {{ estado }}
                  </option>
                </select>
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
