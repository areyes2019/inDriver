<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiConfirmDialog from '@/components/ui/UiConfirmDialog.vue'

interface Asignacion {
  id: number
  id_conductor: number
  conductor_nombre: string
  id_vehiculo: number
  vehiculo_placa: string
  vehiculo_descripcion: string
  fecha_inicio: string
  fecha_fin: string | null
  activo: boolean
}

const route = useRoute()
const slug = computed(() => route.params.slug as string)

const asignaciones = ref<Asignacion[]>([])
const search = ref('')
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')
const finalizando = ref(false)
const asignacionAFinalizar = ref<Asignacion | null>(null)

async function fetchAsignaciones() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await http.get(`/t/${slug.value}/conductor-vehiculo`, {
      params: { search: search.value || undefined, page: page.value },
    })
    asignaciones.value = data.data
    lastPage.value = data.meta?.last_page ?? 1
  } catch {
    error.value = 'No se pudo cargar el historial de asignaciones.'
  } finally {
    loading.value = false
  }
}

function requestFinalizar(asignacion: Asignacion) {
  asignacionAFinalizar.value = asignacion
}

function cancelFinalizar() {
  asignacionAFinalizar.value = null
}

async function confirmFinalizar() {
  const asignacion = asignacionAFinalizar.value
  if (!asignacion) return
  asignacionAFinalizar.value = null

  finalizando.value = true
  try {
    const { data } = await http.patch(
      `/t/${slug.value}/conductor-vehiculo/${asignacion.id}/finalizar`,
    )
    const updated = data.data ?? data
    const index = asignaciones.value.findIndex((a) => a.id === asignacion.id)
    if (index !== -1) asignaciones.value[index] = updated
  } catch {
    error.value = 'No se pudo finalizar la asignación.'
  } finally {
    finalizando.value = false
  }
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined

watch(search, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    page.value = 1
    fetchAsignaciones()
  }, 300)
})

watch(page, () => fetchAsignaciones())

onMounted(fetchAsignaciones)
</script>

<template>
  <TenantLayout>
    <UiCard title="Asignaciones">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <input
          v-model="search"
          type="search"
          placeholder="Buscar por conductor o placa..."
          class="w-full max-w-sm rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
        />
        <RouterLink
          :to="{ name: 'tenant-asignaciones-asignar', params: { slug } }"
          class="rounded-lg bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-heading"
        >
          Asignar vehículo
        </RouterLink>
      </div>

      <p v-if="error" role="alert" class="mb-4 text-sm text-red-600">{{ error }}</p>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[820px] text-left text-sm">
          <thead>
            <tr
              class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
            >
              <th class="py-2 pr-4">Conductor</th>
              <th class="py-2 pr-4">Vehículo</th>
              <th class="py-2 pr-4">Desde</th>
              <th class="py-2 pr-4">Hasta</th>
              <th class="py-2 pr-4">Estado</th>
              <th class="py-2 pr-4">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="6" class="py-6 text-center text-black/50">Cargando...</td>
            </tr>
            <tr v-else-if="asignaciones.length === 0">
              <td colspan="6" class="py-6 text-center text-black/50">No hay asignaciones.</td>
            </tr>
            <tr
              v-for="asignacion in asignaciones"
              v-else
              :key="asignacion.id"
              class="border-b border-gray-100 text-heading"
            >
              <td class="py-2 pr-4 font-medium">{{ asignacion.conductor_nombre }}</td>
              <td class="py-2 pr-4">
                {{ asignacion.vehiculo_placa }}
                <span v-if="asignacion.vehiculo_descripcion" class="text-black/50">
                  ({{ asignacion.vehiculo_descripcion }})
                </span>
              </td>
              <td class="py-2 pr-4">{{ asignacion.fecha_inicio }}</td>
              <td class="py-2 pr-4">{{ asignacion.fecha_fin ?? '—' }}</td>
              <td class="py-2 pr-4">
                <UiBadge
                  :text="asignacion.activo ? 'Activa' : 'Finalizada'"
                  :color="asignacion.activo ? 'green' : 'blue'"
                />
              </td>
              <td class="py-2 pr-4">
                <button
                  v-if="asignacion.activo"
                  type="button"
                  :disabled="finalizando"
                  class="rounded-lg border border-red-600 px-3 py-1.5 text-sm font-semibold text-red-600 transition-colors hover:bg-red-600 hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
                  @click="requestFinalizar(asignacion)"
                >
                  Finalizar
                </button>
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

    <UiConfirmDialog
      :open="asignacionAFinalizar !== null"
      title="Finalizar asignación"
      :message="
        asignacionAFinalizar
          ? `¿Seguro que quieres finalizar la asignación de ${asignacionAFinalizar.vehiculo_placa} a ${asignacionAFinalizar.conductor_nombre}?`
          : ''
      "
      confirm-label="Finalizar"
      @confirm="confirmFinalizar"
      @cancel="cancelFinalizar"
    />
  </TenantLayout>
</template>
