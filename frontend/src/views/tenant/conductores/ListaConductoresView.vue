<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import { useTenantAuthStore } from '@/stores/tenantAuth'

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
const auth = useTenantAuthStore()

const conductores = ref<Conductor[]>([])
const search = ref('')
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')

const estadoColor: Record<string, 'green' | 'orange' | 'blue'> = {
  ACTIVO: 'green',
  INACTIVO: 'blue',
  BLOQUEADO: 'orange',
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

// --- Venta de viajes prepagados ---

const modalidadPrepago = ref(false)
const conductorVenta = ref<Conductor | null>(null)
const formVenta = reactive({ cantidad_viajes: '1' })
const saldoConductorActual = ref<number | null>(null)
const errorVenta = ref('')
const vendiendoViajes = ref(false)

const puedeVenderViajes = computed(
  () => auth.usuario?.rol === 'AdminCliente' && modalidadPrepago.value,
)

async function fetchModalidad() {
  try {
    const { data } = await http.get(`/t/${slug.value}/configuracion`)
    modalidadPrepago.value = data.modalidad_conductores === 'Prepago'
  } catch {
    modalidadPrepago.value = false
  }
}

async function abrirVenderViajes(conductor: Conductor) {
  conductorVenta.value = conductor
  formVenta.cantidad_viajes = '1'
  errorVenta.value = ''
  saldoConductorActual.value = null

  try {
    const { data } = await http.get(
      `/t/${slug.value}/conductores/${conductor.id_conductor}/saldo-viajes`,
    )
    saldoConductorActual.value = data.saldo_viajes
  } catch {
    saldoConductorActual.value = null
  }
}

function cerrarVenderViajes() {
  conductorVenta.value = null
}

async function confirmarVenderViajes() {
  const conductor = conductorVenta.value
  if (!conductor) return

  errorVenta.value = ''
  vendiendoViajes.value = true

  try {
    await http.post(
      `/t/${slug.value}/conductores/${conductor.id_conductor}/vender-viajes`,
      formVenta,
    )
    conductorVenta.value = null
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      errorVenta.value =
        (Object.values(errors)[0] as string[])?.[0] ?? 'No se pudo vender los viajes.'
    } else {
      errorVenta.value = 'No se pudo vender los viajes, intenta de nuevo.'
    }
  } finally {
    vendiendoViajes.value = false
  }
}

onMounted(() => {
  fetchConductores()
  fetchModalidad()
})
</script>

<template>
  <TenantLayout>
    <UiCard title="Conductores">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <input
          v-model="search"
          type="search"
          placeholder="Buscar por nombre, email o licencia..."
          class="w-full max-w-sm rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
        />
        <RouterLink
          :to="{ name: 'tenant-conductores-crear', params: { slug } }"
          class="rounded-lg bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-heading"
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
              class="border-b border-gray-100 text-heading"
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
                <div class="flex flex-wrap gap-2">
                  <RouterLink
                    :to="{
                      name: 'tenant-conductores-editar',
                      params: { slug, id: conductor.id_conductor },
                    }"
                    class="rounded-lg bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-heading"
                  >
                    Editar
                  </RouterLink>
                  <button
                    v-if="puedeVenderViajes"
                    type="button"
                    class="rounded-lg border border-accent px-3 py-1.5 text-sm font-semibold text-accent transition-colors hover:bg-accent hover:text-white"
                    @click="abrirVenderViajes(conductor)"
                  >
                    Vender viajes
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

    <Teleport to="body">
      <div
        v-if="conductorVenta"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        role="dialog"
        aria-modal="true"
        aria-label="Vender viajes prepagados"
      >
        <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-lg shadow-black/10">
          <h2 class="text-base font-semibold text-heading">
            Vender viajes a {{ conductorVenta.nombre }} {{ conductorVenta.apellido_paterno }}
          </h2>
          <p v-if="saldoConductorActual !== null" class="mt-1 text-sm text-black/60">
            Saldo actual del conductor: {{ saldoConductorActual }} viaje(s)
          </p>

          <label class="mt-4 block">
            <span class="mb-1 block text-sm font-medium text-heading">Cantidad de viajes</span>
            <input
              v-model="formVenta.cantidad_viajes"
              type="number"
              min="1"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
          </label>

          <p v-if="errorVenta" role="alert" class="mt-2 text-sm text-red-600">{{ errorVenta }}</p>

          <div class="mt-5 flex justify-end gap-3">
            <button
              type="button"
              class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-heading hover:bg-black/5"
              @click="cerrarVenderViajes"
            >
              Cancelar
            </button>
            <button
              type="button"
              :disabled="vendiendoViajes"
              class="rounded-lg bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
              @click="confirmarVenderViajes"
            >
              Vender
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </TenantLayout>
</template>
