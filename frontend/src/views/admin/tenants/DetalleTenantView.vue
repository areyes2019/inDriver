<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import AdminLayout from '@/layouts/AdminLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiCiudadAutocomplete from '@/components/ui/UiCiudadAutocomplete.vue'
import type { ResolvedCity } from '@/services/maps/types'

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

interface CiudadAsignada {
  id_ciudad: number
  nombre: string
  place_id: string
  lat: number
  lng: number
  bounds: unknown
}

interface AdminCliente {
  id_usuario: number
  nombre: string
  apellido_paterno: string
  email: string
  ciudades: CiudadAsignada[]
}

interface CiudadPendiente {
  place_id: string
  nombre: string
  lat: number
  lng: number
  bounds: unknown
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
  fetchAdminsCliente()
})

// --- Administradores y ciudades ---

const adminsCliente = ref<AdminCliente[]>([])
const ciudadesPendientes = reactive<Record<number, CiudadPendiente[]>>({})
const guardandoCiudades = reactive<Record<number, boolean>>({})
const mensajeCiudades = reactive<Record<number, string>>({})
const errorCiudades = reactive<Record<number, boolean>>({})

async function fetchAdminsCliente() {
  try {
    const { data } = await http.get(`/admin/tenants/${route.params.id}/admins-cliente`)
    adminsCliente.value = data.data ?? data

    for (const admin of adminsCliente.value) {
      ciudadesPendientes[admin.id_usuario] = admin.ciudades.map((ciudad) => ({
        place_id: ciudad.place_id,
        nombre: ciudad.nombre,
        lat: ciudad.lat,
        lng: ciudad.lng,
        bounds: ciudad.bounds,
      }))
    }
  } catch {
    adminsCliente.value = []
  }
}

function agregarCiudad(idUsuario: number, ciudad: ResolvedCity & { placeId: string }) {
  const lista = ciudadesPendientes[idUsuario] ?? (ciudadesPendientes[idUsuario] = [])
  if (lista.some((c) => c.place_id === ciudad.placeId)) return

  lista.push({
    place_id: ciudad.placeId,
    nombre: ciudad.nombre,
    lat: ciudad.lat,
    lng: ciudad.lng,
    bounds: ciudad.bounds,
  })
}

function quitarCiudad(idUsuario: number, placeId: string) {
  ciudadesPendientes[idUsuario] = (ciudadesPendientes[idUsuario] ?? []).filter(
    (c) => c.place_id !== placeId,
  )
}

async function guardarCiudades(idUsuario: number) {
  guardandoCiudades[idUsuario] = true
  mensajeCiudades[idUsuario] = ''

  try {
    await http.put(`/admin/tenants/${route.params.id}/admins-cliente/${idUsuario}/ciudades`, {
      ciudades: ciudadesPendientes[idUsuario] ?? [],
    })
    mensajeCiudades[idUsuario] = 'Ciudades guardadas correctamente.'
    errorCiudades[idUsuario] = false
  } catch {
    mensajeCiudades[idUsuario] = 'No se pudieron guardar las ciudades, intenta de nuevo.'
    errorCiudades[idUsuario] = true
  } finally {
    guardandoCiudades[idUsuario] = false
  }
}

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

    <UiCard v-if="adminsCliente.length" title="Administradores y ciudades" class="mt-6">
      <div
        v-for="admin in adminsCliente"
        :key="admin.id_usuario"
        class="mb-6 border-b border-gray-100 pb-6 last:mb-0 last:border-0 last:pb-0"
      >
        <p class="text-sm font-semibold text-heading">
          {{ admin.nombre }} {{ admin.apellido_paterno }}
        </p>
        <p class="text-xs text-black/50">{{ admin.email }}</p>

        <div class="mt-3 max-w-sm">
          <UiCiudadAutocomplete @select="(ciudad) => agregarCiudad(admin.id_usuario, ciudad)" />
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
          <span
            v-for="ciudad in ciudadesPendientes[admin.id_usuario]"
            :key="ciudad.place_id"
            class="inline-flex items-center gap-1 rounded-full bg-accent/10 px-3 py-1 text-xs text-heading"
          >
            {{ ciudad.nombre }}
            <button
              type="button"
              class="text-black/40 hover:text-red-600"
              @click="quitarCiudad(admin.id_usuario, ciudad.place_id)"
            >
              ×
            </button>
          </span>
          <span v-if="!ciudadesPendientes[admin.id_usuario]?.length" class="text-xs text-black/40">
            Sin ciudades asignadas.
          </span>
        </div>

        <button
          type="button"
          :disabled="guardandoCiudades[admin.id_usuario]"
          class="mt-3 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60"
          @click="guardarCiudades(admin.id_usuario)"
        >
          Guardar
        </button>
        <p
          v-if="mensajeCiudades[admin.id_usuario]"
          class="mt-2 text-sm"
          :class="errorCiudades[admin.id_usuario] ? 'text-red-600' : 'text-green-600'"
        >
          {{ mensajeCiudades[admin.id_usuario] }}
        </p>
      </div>
    </UiCard>
  </AdminLayout>
</template>
