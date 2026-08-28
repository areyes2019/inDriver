<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import http from '@/lib/http'
import AdminLayout from '@/layouts/AdminLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiConfirmDialog from '@/components/ui/UiConfirmDialog.vue'

interface PaqueteViaje {
  id_paquete: number
  codigo_paquete: string
  nombre: string
  descripcion: string | null
  cantidad_viajes: number
  precio: string
  estado: string
  created_at: string
}

const paquetes = ref<PaqueteViaje[]>([])
const search = ref('')
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')
const togglingId = ref<number | null>(null)
const deletingId = ref<number | null>(null)
const paqueteToToggle = ref<PaqueteViaje | null>(null)
const paqueteToDelete = ref<PaqueteViaje | null>(null)

const estadoColor: Record<string, 'green' | 'yellow' | 'blue'> = {
  Activo: 'green',
  Inactivo: 'yellow',
}

async function fetchPaquetes() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await http.get('/admin/paquetes-viajes', {
      params: { search: search.value || undefined, page: page.value },
    })
    paquetes.value = data.data
    lastPage.value = data.meta?.last_page ?? 1
  } catch {
    error.value = 'No se pudo cargar la lista de paquetes.'
  } finally {
    loading.value = false
  }
}

function requestToggleEstado(paquete: PaqueteViaje) {
  paqueteToToggle.value = paquete
}

function cancelToggleEstado() {
  paqueteToToggle.value = null
}

async function confirmToggleEstado() {
  const paquete = paqueteToToggle.value
  if (!paquete) return
  paqueteToToggle.value = null

  togglingId.value = paquete.id_paquete
  try {
    const { data } = await http.patch(`/admin/paquetes-viajes/${paquete.id_paquete}/estado`)
    const updated = data.data ?? data
    const index = paquetes.value.findIndex((p) => p.id_paquete === paquete.id_paquete)
    if (index !== -1) paquetes.value[index] = updated
  } catch {
    error.value = 'No se pudo cambiar el estado del paquete.'
  } finally {
    togglingId.value = null
  }
}

function requestDelete(paquete: PaqueteViaje) {
  paqueteToDelete.value = paquete
}

function cancelDelete() {
  paqueteToDelete.value = null
}

async function confirmDelete() {
  const paquete = paqueteToDelete.value
  if (!paquete) return
  paqueteToDelete.value = null

  deletingId.value = paquete.id_paquete
  try {
    await http.delete(`/admin/paquetes-viajes/${paquete.id_paquete}`)
    paquetes.value = paquetes.value.filter((p) => p.id_paquete !== paquete.id_paquete)
  } catch {
    error.value = 'No se pudo eliminar el paquete.'
  } finally {
    deletingId.value = null
  }
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined

watch(search, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    page.value = 1
    fetchPaquetes()
  }, 300)
})

watch(page, () => fetchPaquetes())

onMounted(fetchPaquetes)
</script>

<template>
  <AdminLayout>
    <UiCard title="Paquetes de viajes">
      <div class="mb-4 flex items-center justify-between gap-3">
        <input
          v-model="search"
          type="search"
          placeholder="Buscar por nombre..."
          class="w-full max-w-sm rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
        />
        <RouterLink
          :to="{ name: 'admin-paquetes-crear' }"
          class="shrink-0 rounded-lg bg-brand-blue px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-dark"
        >
          Crear paquete
        </RouterLink>
      </div>

      <p v-if="error" role="alert" class="mb-4 text-sm text-red-600">{{ error }}</p>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-left text-sm">
          <thead>
            <tr
              class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
            >
              <th class="py-2 pr-4">Código</th>
              <th class="py-2 pr-4">Nombre</th>
              <th class="py-2 pr-4">Viajes</th>
              <th class="py-2 pr-4">Precio</th>
              <th class="py-2 pr-4">Estado</th>
              <th class="py-2 pr-4">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="6" class="py-6 text-center text-black/50">Cargando...</td>
            </tr>
            <tr v-else-if="paquetes.length === 0">
              <td colspan="6" class="py-6 text-center text-black/50">No hay paquetes.</td>
            </tr>
            <tr
              v-for="paquete in paquetes"
              v-else
              :key="paquete.id_paquete"
              class="border-b border-gray-100 text-brand-dark"
            >
              <td class="py-2 pr-4 font-medium">{{ paquete.codigo_paquete }}</td>
              <td class="py-2 pr-4">{{ paquete.nombre }}</td>
              <td class="py-2 pr-4">{{ paquete.cantidad_viajes }}</td>
              <td class="py-2 pr-4">{{ paquete.precio }}</td>
              <td class="py-2 pr-4">
                <UiBadge :text="paquete.estado" :color="estadoColor[paquete.estado] ?? 'blue'" />
              </td>
              <td class="py-2 pr-4">
                <div class="flex flex-wrap gap-2">
                  <RouterLink
                    :to="{ name: 'admin-paquetes-editar', params: { id: paquete.id_paquete } }"
                    class="rounded-lg bg-brand-blue px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark"
                  >
                    Editar
                  </RouterLink>
                  <button
                    type="button"
                    :disabled="togglingId === paquete.id_paquete"
                    class="rounded-lg border border-brand-blue px-3 py-1.5 text-sm font-semibold text-brand-blue transition-colors hover:bg-brand-blue hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
                    @click="requestToggleEstado(paquete)"
                  >
                    {{ paquete.estado === 'Activo' ? 'Desactivar' : 'Activar' }}
                  </button>
                  <button
                    type="button"
                    :disabled="deletingId === paquete.id_paquete"
                    class="rounded-lg border border-red-600 px-3 py-1.5 text-sm font-semibold text-red-600 transition-colors hover:bg-red-600 hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
                    @click="requestDelete(paquete)"
                  >
                    Eliminar
                  </button>
                </div>
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

    <UiConfirmDialog
      :open="paqueteToToggle !== null"
      title="Confirmar cambio de estado"
      :message="
        paqueteToToggle
          ? `¿Seguro que quieres ${paqueteToToggle.estado === 'Activo' ? 'desactivar' : 'activar'} el paquete ${paqueteToToggle.nombre}?`
          : ''
      "
      :confirm-label="paqueteToToggle && paqueteToToggle.estado === 'Activo' ? 'Desactivar' : 'Activar'"
      @confirm="confirmToggleEstado"
      @cancel="cancelToggleEstado"
    />

    <UiConfirmDialog
      :open="paqueteToDelete !== null"
      title="Confirmar eliminación"
      :message="
        paqueteToDelete
          ? `¿Seguro que quieres eliminar el paquete ${paqueteToDelete.nombre}? Ya no podrá comprarse.`
          : ''
      "
      confirm-label="Eliminar"
      @confirm="confirmDelete"
      @cancel="cancelDelete"
    />
  </AdminLayout>
</template>
