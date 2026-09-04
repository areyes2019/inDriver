<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import { useTenantAuthStore } from '@/stores/tenantAuth'

interface DespachadorInfo {
  id_despachador: number
  nombre: string
  apellido_paterno: string
}

interface VehiculoInfo {
  id_vehiculo: number
  placa: string
  marca: string | null
}

interface Conductor {
  id_conductor: number
  id_usuario: number
  nombre: string
  apellido_paterno: string
  email: string
  numero_licencia: string
  fecha_vencimiento_licencia: string | null
  estado: string
  disponibilidad: string
  id_despachador: number | null
  despachador: DespachadorInfo | null
  vehiculo: VehiculoInfo | null
  saldo_viajes: number | null
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
const formVenta = reactive({ monto_pagado: '' })
const saldoConductorActual = ref<number | null>(null)
const errorVenta = ref('')
const exitoVenta = ref('')
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

async function refrescarSaldoConductor(conductor: Conductor) {
  try {
    const { data } = await http.get(
      `/t/${slug.value}/conductores/${conductor.id_conductor}/saldo-viajes`,
    )
    saldoConductorActual.value = data.saldo_viajes
  } catch {
    saldoConductorActual.value = null
  }
}

async function abrirVenderViajes(conductor: Conductor) {
  conductorVenta.value = conductor
  formVenta.monto_pagado = ''
  errorVenta.value = ''
  exitoVenta.value = ''
  saldoConductorActual.value = null

  await refrescarSaldoConductor(conductor)
}

function cerrarVenderViajes() {
  conductorVenta.value = null
}

async function confirmarVenderViajes() {
  const conductor = conductorVenta.value
  if (!conductor) return

  errorVenta.value = ''
  exitoVenta.value = ''
  vendiendoViajes.value = true

  try {
    const { data } = await http.post(
      `/t/${slug.value}/conductores/${conductor.id_conductor}/vender-viajes`,
      formVenta,
    )
    exitoVenta.value = `Se acreditaron ${data.cantidad_viajes} viaje(s) por $${data.monto_pagado}.`
    formVenta.monto_pagado = ''
    await refrescarSaldoConductor(conductor)
    await fetchConductores()
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      errorVenta.value =
        (Object.values(errors)[0] as string[])?.[0] ?? 'No se pudo acreditar el pago.'
    } else {
      errorVenta.value = 'No se pudo acreditar el pago, intenta de nuevo.'
    }
  } finally {
    vendiendoViajes.value = false
  }
}

// --- Historial de pagos de un conductor ---

interface PagoConductor {
  id_venta: number
  fecha_venta: string
  monto_pagado: string
  cantidad_viajes: number
}

const conductorHistorial = ref<Conductor | null>(null)
const historialPagos = ref<PagoConductor[]>([])
const totalPagadoHistorial = ref(0)
const cargandoHistorial = ref(false)
const errorHistorial = ref('')

async function abrirHistorialPagos(conductor: Conductor) {
  conductorHistorial.value = conductor
  historialPagos.value = []
  totalPagadoHistorial.value = 0
  errorHistorial.value = ''
  cargandoHistorial.value = true

  try {
    const { data } = await http.get(
      `/t/${slug.value}/conductores/${conductor.id_conductor}/historial-pagos`,
    )
    historialPagos.value = data.data
    totalPagadoHistorial.value = data.total_pagado
  } catch {
    errorHistorial.value = 'No se pudo cargar el historial de pagos.'
  } finally {
    cargandoHistorial.value = false
  }
}

function cerrarHistorialPagos() {
  conductorHistorial.value = null
}

// --- Despachador asignado (solo si el tenant usa despachadores, spec tenant/011) ---

const usaDespachadores = computed(() => auth.usuario?.usar_despachadores === 'Sí')
const columnasTabla = computed(
  () => 7 + (usaDespachadores.value ? 1 : 0) + (modalidadPrepago.value ? 1 : 0),
)
const despachadoresActivos = ref<{ id_despachador: number; nombre: string }[]>([])
// Con 0 o 1 despachador activo no se pide el selector: sin ninguno no hay nada que elegir, y con
// uno solo se asigna automático en el backend.
const mostrarSelectorDespachador = computed(
  () => usaDespachadores.value && despachadoresActivos.value.length >= 2,
)
const reasignandoId = ref<number | null>(null)
const errorReasignar = ref('')

async function fetchDespachadoresActivos() {
  if (!usaDespachadores.value) return

  try {
    const { data } = await http.get(`/t/${slug.value}/despachadores/activos`)
    despachadoresActivos.value = data.data
  } catch {
    despachadoresActivos.value = []
  }
}

async function onCambiarDespachador(conductor: Conductor, idDespachador: string) {
  errorReasignar.value = ''
  reasignandoId.value = conductor.id_conductor

  try {
    const { data } = await http.put(`/t/${slug.value}/conductores/${conductor.id_conductor}`, {
      numero_licencia: conductor.numero_licencia,
      fecha_vencimiento_licencia: conductor.fecha_vencimiento_licencia,
      estado: conductor.estado,
      disponibilidad: conductor.disponibilidad,
      id_despachador: idDespachador,
    })
    const actualizado = data.data ?? data
    const index = conductores.value.findIndex((c) => c.id_conductor === conductor.id_conductor)
    if (index !== -1) conductores.value[index] = actualizado
  } catch {
    errorReasignar.value = 'No se pudo reasignar el despachador, intenta de nuevo.'
  } finally {
    reasignandoId.value = null
  }
}

onMounted(() => {
  fetchConductores()
  fetchModalidad()
  fetchDespachadoresActivos()
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
        <div class="flex gap-2">
          <RouterLink
            v-if="auth.usuario?.rol === 'AdminCliente'"
            :to="{ name: 'tenant-reporte-pagos-conductores', params: { slug } }"
            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-semibold text-heading transition-colors hover:bg-black/5"
          >
            Reporte de pagos
          </RouterLink>
          <RouterLink
            :to="{ name: 'tenant-conductores-crear', params: { slug } }"
            class="rounded-lg bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-heading"
          >
            Nuevo conductor
          </RouterLink>
        </div>
      </div>

      <p v-if="error" role="alert" class="mb-4 text-sm text-red-600">{{ error }}</p>
      <p v-if="errorReasignar" role="alert" class="mb-4 text-sm text-red-600">
        {{ errorReasignar }}
      </p>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-left text-sm">
          <thead>
            <tr
              class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
            >
              <th class="py-2 pr-4">Nombre</th>
              <th class="py-2 pr-4">Email</th>
              <th class="py-2 pr-4">Licencia</th>
              <th class="py-2 pr-4">Placa</th>
              <th class="py-2 pr-4">Estado</th>
              <th class="py-2 pr-4">Disponibilidad</th>
              <th v-if="usaDespachadores" class="py-2 pr-4">Despachador</th>
              <th v-if="modalidadPrepago" class="py-2 pr-4">Saldo de viajes</th>
              <th class="py-2 pr-4">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="columnasTabla" class="py-6 text-center text-black/50">Cargando...</td>
            </tr>
            <tr v-else-if="conductores.length === 0">
              <td :colspan="columnasTabla" class="py-6 text-center text-black/50">
                No hay conductores.
              </td>
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
              <td class="py-2 pr-4">{{ conductor.vehiculo?.placa ?? '—' }}</td>
              <td class="py-2 pr-4">
                <UiBadge
                  :text="conductor.estado"
                  :color="estadoColor[conductor.estado] ?? 'blue'"
                />
              </td>
              <td class="py-2 pr-4">{{ conductor.disponibilidad }}</td>
              <td v-if="usaDespachadores" class="py-2 pr-4">
                <select
                  v-if="mostrarSelectorDespachador"
                  :value="conductor.id_despachador ?? ''"
                  :disabled="reasignandoId === conductor.id_conductor"
                  class="rounded-lg border border-gray-300 px-2 py-1 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
                  @change="
                    onCambiarDespachador(conductor, ($event.target as HTMLSelectElement).value)
                  "
                >
                  <option value="" disabled>Sin asignar</option>
                  <option
                    v-for="despachador in despachadoresActivos"
                    :key="despachador.id_despachador"
                    :value="despachador.id_despachador"
                  >
                    {{ despachador.nombre }}
                  </option>
                </select>
                <span v-else-if="conductor.despachador">
                  {{ conductor.despachador.nombre }} {{ conductor.despachador.apellido_paterno }}
                </span>
                <UiBadge v-else text="Sin asignar" color="orange" />
              </td>
              <td v-if="modalidadPrepago" class="py-2 pr-4">
                {{ conductor.saldo_viajes ?? 0 }} viaje(s)
              </td>
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
                  <button
                    v-if="puedeVenderViajes"
                    type="button"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-semibold text-heading transition-colors hover:bg-black/5"
                    @click="abrirHistorialPagos(conductor)"
                  >
                    Historial de pagos
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
            <span class="mb-1 block text-sm font-medium text-heading">Monto pagado ($)</span>
            <input
              v-model="formVenta.monto_pagado"
              type="number"
              step="0.01"
              min="0.01"
              placeholder="500.00"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
          </label>

          <p v-if="errorVenta" role="alert" class="mt-2 text-sm text-red-600">{{ errorVenta }}</p>
          <p v-if="exitoVenta" class="mt-2 text-sm text-green-600">{{ exitoVenta }}</p>

          <div class="mt-5 flex justify-end gap-3">
            <button
              type="button"
              class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-heading hover:bg-black/5"
              @click="cerrarVenderViajes"
            >
              Cerrar
            </button>
            <button
              type="button"
              :disabled="vendiendoViajes"
              class="rounded-lg bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
              @click="confirmarVenderViajes"
            >
              Acreditar
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="conductorHistorial"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        role="dialog"
        aria-modal="true"
        aria-label="Historial de pagos"
      >
        <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-lg shadow-black/10">
          <h2 class="text-base font-semibold text-heading">
            Historial de pagos de {{ conductorHistorial.nombre }}
            {{ conductorHistorial.apellido_paterno }}
          </h2>

          <p v-if="cargandoHistorial" class="mt-3 text-sm text-black/50">Cargando...</p>
          <p v-else-if="errorHistorial" role="alert" class="mt-3 text-sm text-red-600">
            {{ errorHistorial }}
          </p>
          <template v-else>
            <p v-if="historialPagos.length === 0" class="mt-3 text-sm text-black/50">
              Este conductor no tiene pagos registrados.
            </p>
            <div v-else class="mt-3 max-h-72 overflow-y-auto">
              <table class="w-full text-left text-sm">
                <thead>
                  <tr class="text-xs font-semibold tracking-wide text-black/50 uppercase">
                    <th class="py-1 pr-3">Fecha</th>
                    <th class="py-1 pr-3">Monto</th>
                    <th class="py-1">Viajes</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="pago in historialPagos"
                    :key="pago.id_venta"
                    class="border-t border-gray-100 text-heading"
                  >
                    <td class="py-1.5 pr-3">
                      {{ new Date(pago.fecha_venta).toLocaleDateString() }}
                    </td>
                    <td class="py-1.5 pr-3">${{ pago.monto_pagado }}</td>
                    <td class="py-1.5">{{ pago.cantidad_viajes }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p class="mt-3 text-sm font-semibold text-heading">
              Total pagado: ${{ totalPagadoHistorial }}
            </p>
          </template>

          <div class="mt-5 flex justify-end">
            <button
              type="button"
              class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-heading hover:bg-black/5"
              @click="cerrarHistorialPagos"
            >
              Cerrar
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </TenantLayout>
</template>
