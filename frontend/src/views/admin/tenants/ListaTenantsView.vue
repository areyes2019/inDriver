<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import axios from 'axios'
import http from '@/lib/http'
import AdminLayout from '@/layouts/AdminLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiConfirmDialog from '@/components/ui/UiConfirmDialog.vue'

interface Tenant {
  id_tenant: number
  nombre_comercial: string
  razon_social: string
  rfc: string | null
  telefono: string | null
  email: string | null
  estado: string
  modo_estado: string
  created_at: string
}

const tenants = ref<Tenant[]>([])
const search = ref('')
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')
const togglingId = ref<number | null>(null)
const tenantToToggle = ref<Tenant | null>(null)
const tenantToDelete = ref<Tenant | null>(null)
const deleting = ref(false)
const deleteError = ref('')

const estadoColor: Record<string, 'green' | 'orange' | 'blue'> = {
  Activo: 'green',
  Suspendido: 'orange',
  Inactivo: 'blue',
}

async function fetchTenants() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await http.get('/admin/tenants', {
      params: { search: search.value || undefined, page: page.value },
    })
    tenants.value = data.data
    lastPage.value = data.meta?.last_page ?? 1
  } catch {
    error.value = 'No se pudo cargar la lista de tenants.'
  } finally {
    loading.value = false
  }
}

function accionPara(tenant: Tenant) {
  return tenant.estado === 'Activo' ? 'suspender' : 'activar'
}

function requestToggleEstado(tenant: Tenant) {
  tenantToToggle.value = tenant
}

function cancelToggleEstado() {
  tenantToToggle.value = null
}

async function confirmToggleEstado() {
  const tenant = tenantToToggle.value
  if (!tenant) return
  tenantToToggle.value = null

  togglingId.value = tenant.id_tenant
  try {
    const { data } = await http.patch(`/admin/tenants/${tenant.id_tenant}/estado`)
    const updated = data.data ?? data
    const index = tenants.value.findIndex((t) => t.id_tenant === tenant.id_tenant)
    if (index !== -1) tenants.value[index] = updated
  } catch {
    error.value = 'No se pudo cambiar el estado del tenant.'
  } finally {
    togglingId.value = null
  }
}

function requestDelete(tenant: Tenant) {
  deleteError.value = ''
  tenantToDelete.value = tenant
}

function cancelDelete() {
  tenantToDelete.value = null
  deleteError.value = ''
}

async function confirmDelete(password?: string) {
  const tenant = tenantToDelete.value
  if (!tenant) return

  deleting.value = true
  deleteError.value = ''
  try {
    await http.delete(`/admin/tenants/${tenant.id_tenant}`, { data: { password } })
    tenants.value = tenants.value.filter((t) => t.id_tenant !== tenant.id_tenant)
    tenantToDelete.value = null
  } catch (err) {
    deleteError.value =
      (axios.isAxiosError(err) && err.response?.data?.errors?.password?.[0]) ??
      'No se pudo eliminar el tenant.'
  } finally {
    deleting.value = false
  }
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined

watch(search, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    page.value = 1
    fetchTenants()
  }, 300)
})

watch(page, () => fetchTenants())

onMounted(fetchTenants)
</script>

<template>
  <AdminLayout>
    <UiCard title="Tenants">
      <div class="mb-4">
        <input
          v-model="search"
          type="search"
          placeholder="Buscar por nombre comercial..."
          class="w-full max-w-sm rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
        />
      </div>

      <p v-if="error" role="alert" class="mb-4 text-sm text-red-600">{{ error }}</p>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-left text-sm">
          <thead>
            <tr
              class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
            >
              <th class="py-2 pr-4">Nombre comercial</th>
              <th class="py-2 pr-4">RFC</th>
              <th class="py-2 pr-4">Email</th>
              <th class="py-2 pr-4">Teléfono</th>
              <th class="py-2 pr-4">Estado</th>
              <th class="py-2 pr-4">Modo</th>
              <th class="py-2 pr-4">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="7" class="py-6 text-center text-black/50">Cargando...</td>
            </tr>
            <tr v-else-if="tenants.length === 0">
              <td colspan="7" class="py-6 text-center text-black/50">No hay tenants.</td>
            </tr>
            <tr
              v-for="tenant in tenants"
              v-else
              :key="tenant.id_tenant"
              class="border-b border-gray-100 text-heading"
            >
              <td class="py-2 pr-4 font-medium">{{ tenant.nombre_comercial }}</td>
              <td class="py-2 pr-4">{{ tenant.rfc ?? '—' }}</td>
              <td class="py-2 pr-4">{{ tenant.email ?? '—' }}</td>
              <td class="py-2 pr-4">{{ tenant.telefono ?? '—' }}</td>
              <td class="py-2 pr-4">
                <UiBadge :text="tenant.estado" :color="estadoColor[tenant.estado] ?? 'blue'" />
              </td>
              <td class="py-2 pr-4 text-black/60">{{ tenant.modo_estado }}</td>
              <td class="py-2 pr-4">
                <div class="flex flex-wrap gap-2">
                  <RouterLink
                    :to="{ name: 'admin-tenants-detalle', params: { id: tenant.id_tenant } }"
                    class="rounded-lg bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-heading"
                  >
                    Ver detalle
                  </RouterLink>
                  <button
                    type="button"
                    :disabled="togglingId === tenant.id_tenant || tenant.estado === 'Inactivo'"
                    class="rounded-lg border border-accent px-3 py-1.5 text-sm font-semibold text-accent transition-colors hover:bg-accent hover:text-white disabled:cursor-not-allowed disabled:border-gray-300 disabled:text-gray-400 disabled:hover:bg-transparent disabled:hover:text-gray-400 disabled:opacity-50"
                    @click="requestToggleEstado(tenant)"
                  >
                    {{ tenant.estado === 'Activo' ? 'Suspender' : 'Activar' }}
                  </button>
                  <button
                    type="button"
                    class="rounded-lg border border-red-600 px-3 py-1.5 text-sm font-semibold text-red-600 transition-colors hover:bg-red-600 hover:text-white"
                    @click="requestDelete(tenant)"
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
      :open="tenantToToggle !== null"
      title="Confirmar cambio de estado"
      :message="
        tenantToToggle
          ? `¿Seguro que quieres ${accionPara(tenantToToggle)} a ${tenantToToggle.nombre_comercial}?`
          : ''
      "
      :confirm-label="
        tenantToToggle && tenantToToggle.estado === 'Activo' ? 'Suspender' : 'Activar'
      "
      @confirm="confirmToggleEstado"
      @cancel="cancelToggleEstado"
    />

    <UiConfirmDialog
      :open="tenantToDelete !== null"
      title="Eliminar tenant"
      :message="
        tenantToDelete
          ? `Esta acción es irreversible: se borrará ${tenantToDelete.nombre_comercial} y toda su base de datos. Ingresa tu contraseña para confirmar.`
          : ''
      "
      confirm-label="Eliminar"
      require-password
      :password-error="deleteError"
      @confirm="confirmDelete"
      @cancel="cancelDelete"
    />
  </AdminLayout>
</template>
