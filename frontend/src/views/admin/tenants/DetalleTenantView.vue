<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import AdminLayout from '@/layouts/AdminLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'

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

interface PaqueteViaje {
  id_paquete: number
  nombre: string
  cantidad_viajes: number
  precio: string
  estado: string
}

const route = useRoute()
const tenant = ref<Tenant | null>(null)
const error = ref('')
const loading = ref(true)

const estadoColor: Record<string, 'green' | 'orange' | 'blue'> = {
  Activo: 'green',
  Suspendido: 'orange',
  Inactivo: 'blue',
}

onMounted(async () => {
  try {
    const { data } = await http.get(`/admin/tenants/${route.params.id}`)
    tenant.value = data.data ?? data
  } catch {
    error.value = 'No se pudo cargar el tenant.'
  } finally {
    loading.value = false
  }

  fetchPaquetesActivos()
})

// --- Acreditar paquete ---

const paquetesActivos = ref<PaqueteViaje[]>([])
const formCredito = reactive({ id_paquete: '', cantidad_paquetes: '1' })
const errorCredito = ref('')
const successCredito = ref('')
const acreditando = ref(false)

async function fetchPaquetesActivos() {
  try {
    const { data } = await http.get('/admin/paquetes-viajes', { params: { search: '' } })
    paquetesActivos.value = (data.data ?? []).filter((p: PaqueteViaje) => p.estado === 'Activo')
  } catch {
    paquetesActivos.value = []
  }
}

async function onAcreditarPaquete() {
  errorCredito.value = ''
  successCredito.value = ''
  acreditando.value = true

  try {
    const { data } = await http.post(`/admin/tenants/${route.params.id}/creditos-paquetes`, {
      id_paquete: formCredito.id_paquete,
      cantidad_paquetes: formCredito.cantidad_paquetes,
    })
    successCredito.value = `Se acreditaron ${data.cantidad_viajes_acreditados} viaje(s) al tenant.`
    formCredito.id_paquete = ''
    formCredito.cantidad_paquetes = '1'
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.data?.message) {
      errorCredito.value = err.response.data.message
    } else {
      errorCredito.value = 'No se pudo acreditar el paquete, intenta de nuevo.'
    }
  } finally {
    acreditando.value = false
  }
}
</script>

<template>
  <AdminLayout>
    <UiCard title="Detalle del tenant">
      <p v-if="loading" class="text-sm text-black/50">Cargando...</p>
      <p v-else-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>

      <dl v-else-if="tenant" class="grid max-w-lg grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <dt class="text-xs font-semibold tracking-wide text-black/50 uppercase">
            Nombre comercial
          </dt>
          <dd class="text-sm text-heading">{{ tenant.nombre_comercial }}</dd>
        </div>
        <div>
          <dt class="text-xs font-semibold tracking-wide text-black/50 uppercase">Razón social</dt>
          <dd class="text-sm text-heading">{{ tenant.razon_social }}</dd>
        </div>
        <div>
          <dt class="text-xs font-semibold tracking-wide text-black/50 uppercase">RFC</dt>
          <dd class="text-sm text-heading">{{ tenant.rfc ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-xs font-semibold tracking-wide text-black/50 uppercase">Teléfono</dt>
          <dd class="text-sm text-heading">{{ tenant.telefono ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-xs font-semibold tracking-wide text-black/50 uppercase">Email</dt>
          <dd class="text-sm text-heading">{{ tenant.email ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-xs font-semibold tracking-wide text-black/50 uppercase">Estado</dt>
          <dd class="mt-0.5">
            <UiBadge :text="tenant.estado" :color="estadoColor[tenant.estado] ?? 'blue'" />
          </dd>
        </div>
        <div>
          <dt class="text-xs font-semibold tracking-wide text-black/50 uppercase">
            Modo de estado
          </dt>
          <dd class="text-sm text-heading">{{ tenant.modo_estado }}</dd>
        </div>
        <div>
          <dt class="text-xs font-semibold tracking-wide text-black/50 uppercase">Alta</dt>
          <dd class="text-sm text-heading">
            {{ new Date(tenant.created_at).toLocaleDateString() }}
          </dd>
        </div>
      </dl>

      <div v-if="tenant" class="mt-8 border-t border-gray-100 pt-4">
        <RouterLink
          :to="{ name: 'admin-tenants-editar', params: { id: tenant.id_tenant } }"
          class="rounded-xl bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-heading"
        >
          Editar
        </RouterLink>
      </div>
    </UiCard>

    <UiCard v-if="tenant" title="Acreditar paquete" class="mt-6">
      <form class="flex flex-wrap items-end gap-3" @submit.prevent="onAcreditarPaquete">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Paquete</span>
          <select
            v-model="formCredito.id_paquete"
            required
            class="w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          >
            <option value="" disabled>Selecciona un paquete...</option>
            <option v-for="p in paquetesActivos" :key="p.id_paquete" :value="p.id_paquete">
              {{ p.nombre }} ({{ p.cantidad_viajes }} viajes, ${{ p.precio }})
            </option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Cantidad</span>
          <input
            v-model="formCredito.cantidad_paquetes"
            type="number"
            min="1"
            required
            class="w-24 rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
        </label>
        <button
          type="submit"
          :disabled="acreditando || !formCredito.id_paquete"
          class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60"
        >
          Acreditar
        </button>
      </form>
      <p v-if="errorCredito" role="alert" class="mt-3 text-sm text-red-600">{{ errorCredito }}</p>
      <p v-if="successCredito" class="mt-3 text-sm text-green-600">{{ successCredito }}</p>
    </UiCard>
  </AdminLayout>
</template>
