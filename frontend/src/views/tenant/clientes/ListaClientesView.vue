<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiConfirmDialog from '@/components/ui/UiConfirmDialog.vue'

interface Cliente {
  id_cliente: number
  nombre: string
  telefono: string | null
  email: string | null
  referencia: string | null
  estado: string
  created_at: string
}

const route = useRoute()
const slug = computed(() => route.params.slug as string)

const clientes = ref<Cliente[]>([])
const search = ref('')
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')
const togglingId = ref<number | null>(null)
const clienteToToggle = ref<Cliente | null>(null)

const estadoColor: Record<string, 'green' | 'blue'> = {
  Activo: 'green',
  Inactivo: 'blue',
}

async function fetchClientes() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await http.get(`/t/${slug.value}/clientes`, {
      params: { search: search.value || undefined, page: page.value },
    })
    clientes.value = data.data
    lastPage.value = data.meta?.last_page ?? 1
  } catch {
    error.value = 'No se pudo cargar la lista de clientes.'
  } finally {
    loading.value = false
  }
}

function accionPara(cliente: Cliente) {
  return cliente.estado === 'Activo' ? 'desactivar' : 'activar'
}

function requestToggleEstado(cliente: Cliente) {
  clienteToToggle.value = cliente
}

function cancelToggleEstado() {
  clienteToToggle.value = null
}

async function confirmToggleEstado() {
  const cliente = clienteToToggle.value
  if (!cliente) return
  clienteToToggle.value = null

  togglingId.value = cliente.id_cliente
  try {
    const { data } = await http.patch(`/t/${slug.value}/clientes/${cliente.id_cliente}/estado`)
    const updated = data.data ?? data
    const index = clientes.value.findIndex((c) => c.id_cliente === cliente.id_cliente)
    if (index !== -1) clientes.value[index] = updated
  } catch {
    error.value = 'No se pudo cambiar el estado del cliente.'
  } finally {
    togglingId.value = null
  }
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined

watch(search, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    page.value = 1
    fetchClientes()
  }, 300)
})

watch(page, () => fetchClientes())

onMounted(fetchClientes)
</script>

<template>
  <TenantLayout>
    <UiCard title="Clientes">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <input
          v-model="search"
          type="search"
          placeholder="Buscar por nombre, teléfono o email..."
          class="w-full max-w-sm rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
        />
        <RouterLink
          :to="{ name: 'tenant-clientes-crear', params: { slug } }"
          class="rounded-lg bg-brand-blue px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark"
        >
          Nuevo cliente
        </RouterLink>
      </div>

      <p v-if="error" role="alert" class="mb-4 text-sm text-red-600">{{ error }}</p>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-left text-sm">
          <thead>
            <tr
              class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
            >
              <th class="py-2 pr-4">Nombre</th>
              <th class="py-2 pr-4">Teléfono</th>
              <th class="py-2 pr-4">Email</th>
              <th class="py-2 pr-4">Estado</th>
              <th class="py-2 pr-4">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="5" class="py-6 text-center text-black/50">Cargando...</td>
            </tr>
            <tr v-else-if="clientes.length === 0">
              <td colspan="5" class="py-6 text-center text-black/50">No hay clientes.</td>
            </tr>
            <tr
              v-for="cliente in clientes"
              v-else
              :key="cliente.id_cliente"
              class="border-b border-gray-100 text-brand-dark"
            >
              <td class="py-2 pr-4 font-medium">{{ cliente.nombre }}</td>
              <td class="py-2 pr-4">{{ cliente.telefono ?? '—' }}</td>
              <td class="py-2 pr-4">{{ cliente.email ?? '—' }}</td>
              <td class="py-2 pr-4">
                <UiBadge :text="cliente.estado" :color="estadoColor[cliente.estado] ?? 'blue'" />
              </td>
              <td class="py-2 pr-4">
                <div class="flex flex-wrap gap-2">
                  <RouterLink
                    :to="{ name: 'tenant-clientes-editar', params: { slug, id: cliente.id_cliente } }"
                    class="rounded-lg bg-brand-blue px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark"
                  >
                    Editar
                  </RouterLink>
                  <button
                    type="button"
                    :disabled="togglingId === cliente.id_cliente"
                    class="rounded-lg border border-brand-blue px-3 py-1.5 text-sm font-semibold text-brand-blue transition-colors hover:bg-brand-blue hover:text-white disabled:cursor-not-allowed disabled:border-gray-300 disabled:text-gray-400 disabled:hover:bg-transparent disabled:hover:text-gray-400 disabled:opacity-50"
                    @click="requestToggleEstado(cliente)"
                  >
                    {{ cliente.estado === 'Activo' ? 'Desactivar' : 'Activar' }}
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
      :open="clienteToToggle !== null"
      title="Confirmar cambio de estado"
      :message="
        clienteToToggle
          ? `¿Seguro que quieres ${accionPara(clienteToToggle)} a ${clienteToToggle.nombre}?`
          : ''
      "
      :confirm-label="clienteToToggle && clienteToToggle.estado === 'Activo' ? 'Desactivar' : 'Activar'"
      @confirm="confirmToggleEstado"
      @cancel="cancelToggleEstado"
    />
  </TenantLayout>
</template>
