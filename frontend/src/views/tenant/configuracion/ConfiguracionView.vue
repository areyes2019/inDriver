<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import { Icon } from '@iconify/vue'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiAlert from '@/components/ui/UiAlert.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiConfirmDialog from '@/components/ui/UiConfirmDialog.vue'
import CambiarPasswordForm from '@/components/tenant/CambiarPasswordForm.vue'
import mapService from '@/services/maps/MapService'
import type { LatLngLike } from '@/services/maps/types'
import { useTenantAuthStore } from '@/stores/tenantAuth'

interface ZonaServicio {
  id_zona: number
  nombre: string
  estado: string
  poligono: Array<{ lat: number; lng: number }> | null
}

const route = useRoute()
const slug = computed(() => route.params.slug as string)
const tenantAuth = useTenantAuthStore()

function puntosCiudadesTenant() {
  return (tenantAuth.usuario?.ciudades_tenant ?? []).map((ciudad) => ({
    lat: ciudad.lat,
    lng: ciudad.lng,
    bounds: ciudad.bounds,
  }))
}

type Pestana = 'tarifas' | 'comision' | 'zonas' | 'cuenta'
const pestanaActiva = ref<Pestana>('tarifas')
const pestanas: Array<{ id: Pestana; label: string }> = [
  { id: 'tarifas', label: 'Tarifas' },
  { id: 'comision', label: 'Comisión / Prepago' },
  { id: 'zonas', label: 'Zonas de cobertura' },
  { id: 'cuenta', label: 'Mi cuenta' },
]

// --- Tarifas + Comisión/Prepago ---

const form = reactive({
  tarifa_banderazo: '0',
  km_incluidos_banderazo: '0',
  tarifa_km_adicional: '0',
  modalidad_conductores: 'Prepago' as 'Prepago' | 'Comision',
  costo_viaje_prepago: '0',
  comision_porcentaje: '0',
  usar_despachadores: 'No' as 'Sí' | 'No',
})

const saldoTenant = ref(0)
const valorOriginalUsarDespachadores = ref<'Sí' | 'No'>('No')
const mostrarConfirmDesactivarDespachadores = ref(false)
const fieldErrors = reactive<Record<string, string>>({})
const errorConfig = ref('')
const successConfig = ref('')
const loadingConfig = ref(false)
const guardando = ref(false)

async function fetchConfiguracion() {
  loadingConfig.value = true
  errorConfig.value = ''

  try {
    const { data } = await http.get(`/t/${slug.value}/configuracion`)
    form.tarifa_banderazo = data.tarifa_banderazo ?? '0'
    form.km_incluidos_banderazo = data.km_incluidos_banderazo ?? '0'
    form.tarifa_km_adicional = data.tarifa_km_adicional ?? '0'
    form.modalidad_conductores = data.modalidad_conductores ?? 'Prepago'
    form.costo_viaje_prepago = data.costo_viaje_prepago ?? '0'
    form.comision_porcentaje = data.comision_porcentaje ?? '0'
    form.usar_despachadores = data.usar_despachadores ?? 'No'
    valorOriginalUsarDespachadores.value = form.usar_despachadores
    saldoTenant.value = data.saldo_viajes_tenant ?? 0
  } catch {
    errorConfig.value = 'No se pudo cargar la configuración.'
  } finally {
    loadingConfig.value = false
  }
}

async function onSubmitConfiguracion() {
  if (valorOriginalUsarDespachadores.value === 'Sí' && form.usar_despachadores === 'No') {
    mostrarConfirmDesactivarDespachadores.value = true
    return
  }

  await guardarConfiguracion()
}

async function confirmarDesactivarDespachadores() {
  mostrarConfirmDesactivarDespachadores.value = false
  await guardarConfiguracion()
}

function cancelarDesactivarDespachadores() {
  mostrarConfirmDesactivarDespachadores.value = false
  form.usar_despachadores = valorOriginalUsarDespachadores.value
}

async function guardarConfiguracion() {
  errorConfig.value = ''
  successConfig.value = ''
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  guardando.value = true

  try {
    const { data } = await http.put(`/t/${slug.value}/configuracion`, form)
    saldoTenant.value = data.saldo_viajes_tenant ?? saldoTenant.value
    valorOriginalUsarDespachadores.value = data.usar_despachadores ?? form.usar_despachadores
    successConfig.value = 'Configuración guardada correctamente.'
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      for (const [field, messages] of Object.entries(errors)) {
        fieldErrors[field] = (messages as string[])[0] ?? ''
      }
    } else {
      errorConfig.value = 'No se pudo guardar la configuración, intenta de nuevo.'
    }
  } finally {
    guardando.value = false
  }
}

// --- Zonas de cobertura ---

const zonas = ref<ZonaServicio[]>([])
const loadingZonas = ref(false)
const errorZonas = ref('')
const nuevaZona = reactive({ nombre: '' })
const creandoZona = ref(false)
const togglingZonaId = ref<number | null>(null)
const zonaToDelete = ref<ZonaServicio | null>(null)
const deletingZonaId = ref<number | null>(null)

async function fetchZonas() {
  loadingZonas.value = true
  errorZonas.value = ''

  try {
    const { data } = await http.get(`/t/${slug.value}/zonas-cobertura`)
    zonas.value = data.data ?? data
  } catch {
    errorZonas.value = 'No se pudo cargar la lista de zonas de cobertura.'
  } finally {
    loadingZonas.value = false
  }
}

// Sin API key de Google configurada no hay picker de mapa: solo se administra el nombre.
async function onCrearZona() {
  if (!nuevaZona.nombre.trim()) return

  creandoZona.value = true
  errorZonas.value = ''

  try {
    const { data } = await http.post(`/t/${slug.value}/zonas-cobertura`, {
      nombre: nuevaZona.nombre,
    })
    zonas.value.push(data.data ?? data)
    nuevaZona.nombre = ''
  } catch {
    errorZonas.value = 'No se pudo crear la zona de cobertura.'
  } finally {
    creandoZona.value = false
  }
}

async function onToggleEstadoZona(zona: ZonaServicio) {
  togglingZonaId.value = zona.id_zona
  try {
    const { data } = await http.patch(`/t/${slug.value}/zonas-cobertura/${zona.id_zona}/estado`)
    const updated = data.data ?? data
    const index = zonas.value.findIndex((z) => z.id_zona === zona.id_zona)
    if (index !== -1) zonas.value[index] = updated
  } catch {
    errorZonas.value = 'No se pudo cambiar el estado de la zona.'
  } finally {
    togglingZonaId.value = null
  }
}

function requestDeleteZona(zona: ZonaServicio) {
  zonaToDelete.value = zona
}

function cancelDeleteZona() {
  zonaToDelete.value = null
}

async function confirmDeleteZona() {
  const zona = zonaToDelete.value
  if (!zona) return
  zonaToDelete.value = null

  deletingZonaId.value = zona.id_zona
  try {
    await http.delete(`/t/${slug.value}/zonas-cobertura/${zona.id_zona}`)
    zonas.value = zonas.value.filter((z) => z.id_zona !== zona.id_zona)
  } catch {
    errorZonas.value = 'No se pudo eliminar la zona.'
  } finally {
    deletingZonaId.value = null
  }
}

// --- Picker visual de la geocerca (dibujar/editar el polígono de una zona) ---

function containerIdZona(idZona: number) {
  return `zona-mapa-${idZona}`
}

const zonaDibujando = ref<number | null>(null)
const puntosPoligono = reactive<Record<number, LatLngLike[]>>({})
const guardandoPoligonoId = ref<number | null>(null)

async function abrirDibujoZona(zona: ZonaServicio) {
  if (zonaDibujando.value === zona.id_zona) {
    cerrarDibujoZona(zona.id_zona)
    return
  }
  if (zonaDibujando.value !== null) cerrarDibujoZona(zonaDibujando.value)
  if (nuevaZonaAbierta.value) cerrarNuevaZona()

  errorZonas.value = ''
  zonaDibujando.value = zona.id_zona
  puntosPoligono[zona.id_zona] = zona.poligono ?? []

  await nextTick()
  if (!mapService.hasApiKey()) return

  const containerId = containerIdZona(zona.id_zona)
  await mapService.initialize(containerId, { zoom: 12 })
  const ciudades = puntosCiudadesTenant()
  if (ciudades.length > 0) mapService.fitToPositions(containerId, ciudades)
  mapService.enablePolygonDrawing(containerId, {
    initialPoints: zona.poligono ?? undefined,
    onChange: (points) => {
      puntosPoligono[zona.id_zona] = points
    },
  })
}

function cerrarDibujoZona(idZona: number) {
  const containerId = containerIdZona(idZona)
  mapService.disablePolygonDrawing(containerId)
  mapService.destroy(containerId)
  if (zonaDibujando.value === idZona) zonaDibujando.value = null
}

async function guardarPoligonoZona(zona: ZonaServicio) {
  const puntos = puntosPoligono[zona.id_zona] ?? []
  if (puntos.length < 3) {
    errorZonas.value = 'Dibuja al menos 3 vértices antes de guardar la geocerca.'
    return
  }

  guardandoPoligonoId.value = zona.id_zona
  errorZonas.value = ''
  try {
    const { data } = await http.put(`/t/${slug.value}/zonas-cobertura/${zona.id_zona}`, {
      nombre: zona.nombre,
      poligono: puntos,
    })
    const updated = data.data ?? data
    const index = zonas.value.findIndex((z) => z.id_zona === zona.id_zona)
    if (index !== -1) zonas.value[index] = updated
    cerrarDibujoZona(zona.id_zona)
  } catch {
    errorZonas.value = 'No se pudo guardar la geocerca.'
  } finally {
    guardandoPoligonoId.value = null
  }
}

// --- Alta de una geocerca nueva: se dibuja primero, se nombra y guarda después, en un solo paso ---

const NUEVA_ZONA_CONTAINER = 'zona-mapa-nueva'
const nuevaZonaAbierta = ref(false)
const nombreNuevaZona = ref('')
const puntosNuevaZona = ref<LatLngLike[]>([])

async function abrirNuevaZona() {
  if (zonaDibujando.value !== null) cerrarDibujoZona(zonaDibujando.value)

  errorZonas.value = ''
  nuevaZonaAbierta.value = true
  nombreNuevaZona.value = ''
  puntosNuevaZona.value = []

  await nextTick()
  if (!mapService.hasApiKey()) return

  await mapService.initialize(NUEVA_ZONA_CONTAINER, { zoom: 12 })
  const ciudades = puntosCiudadesTenant()
  if (ciudades.length > 0) mapService.fitToPositions(NUEVA_ZONA_CONTAINER, ciudades)
  mapService.enablePolygonDrawing(NUEVA_ZONA_CONTAINER, {
    onChange: (points) => {
      puntosNuevaZona.value = points
    },
  })
}

function cerrarNuevaZona() {
  mapService.disablePolygonDrawing(NUEVA_ZONA_CONTAINER)
  mapService.destroy(NUEVA_ZONA_CONTAINER)
  nuevaZonaAbierta.value = false
}

async function guardarNuevaZona() {
  if (!nombreNuevaZona.value.trim()) {
    errorZonas.value = 'Escribe un nombre para la geocerca.'
    return
  }
  if (puntosNuevaZona.value.length < 3) {
    errorZonas.value = 'Dibuja al menos 3 vértices antes de guardar la geocerca.'
    return
  }

  creandoZona.value = true
  errorZonas.value = ''
  try {
    const { data } = await http.post(`/t/${slug.value}/zonas-cobertura`, {
      nombre: nombreNuevaZona.value,
      poligono: puntosNuevaZona.value,
    })
    zonas.value.push(data.data ?? data)
    cerrarNuevaZona()
  } catch {
    errorZonas.value = 'No se pudo crear la zona de cobertura.'
  } finally {
    creandoZona.value = false
  }
}

onMounted(() => {
  fetchConfiguracion()
  fetchZonas()
})

onBeforeUnmount(() => {
  if (zonaDibujando.value !== null) cerrarDibujoZona(zonaDibujando.value)
  if (nuevaZonaAbierta.value) cerrarNuevaZona()
})
</script>

<template>
  <TenantLayout>
    <UiCard title="Configuración">
      <div class="mb-5 flex gap-2 border-b border-gray-200">
        <button
          v-for="p in pestanas"
          :key="p.id"
          type="button"
          class="border-b-2 px-3 py-2 text-sm font-semibold transition-colors"
          :class="
            pestanaActiva === p.id
              ? 'border-accent text-accent'
              : 'border-transparent text-body hover:text-heading'
          "
          @click="pestanaActiva = p.id"
        >
          {{ p.label }}
        </button>
      </div>

      <p v-if="loadingConfig" class="text-sm text-black/50">Cargando...</p>

      <template v-else>
        <form
          v-if="pestanaActiva === 'tarifas'"
          class="max-w-lg space-y-5"
          @submit.prevent="onSubmitConfiguracion"
        >
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Tarifa por banderazo</span>
            <input
              v-model="form.tarifa_banderazo"
              type="number"
              step="0.01"
              min="0"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
            <span v-if="fieldErrors.tarifa_banderazo" class="mt-1 block text-sm text-red-600">
              {{ fieldErrors.tarifa_banderazo }}
            </span>
          </label>

          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">
              Kilómetros incluidos en el banderazo
            </span>
            <input
              v-model="form.km_incluidos_banderazo"
              type="number"
              step="0.01"
              min="0.01"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
            <span v-if="fieldErrors.km_incluidos_banderazo" class="mt-1 block text-sm text-red-600">
              {{ fieldErrors.km_incluidos_banderazo }}
            </span>
          </label>

          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">
              Tarifa por kilómetro adicional
            </span>
            <input
              v-model="form.tarifa_km_adicional"
              type="number"
              step="0.01"
              min="0"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
            <span v-if="fieldErrors.tarifa_km_adicional" class="mt-1 block text-sm text-red-600">
              {{ fieldErrors.tarifa_km_adicional }}
            </span>
          </label>

          <p v-if="errorConfig" role="alert" class="text-sm text-red-600">{{ errorConfig }}</p>
          <p v-if="successConfig" class="text-sm text-green-600">{{ successConfig }}</p>

          <div class="mt-2 border-t border-gray-100 pt-4">
            <button
              type="submit"
              :disabled="guardando"
              class="w-full rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
            >
              Guardar tarifas
            </button>
          </div>
        </form>

        <form
          v-else-if="pestanaActiva === 'comision'"
          class="max-w-lg space-y-5"
          @submit.prevent="onSubmitConfiguracion"
        >
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading"
              >¿Utilizar despachadores?</span
            >
            <select
              v-model="form.usar_despachadores"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            >
              <option value="No">No — yo administro directamente conductores y pedidos</option>
              <option value="Sí">Sí — mi flotilla trabaja con despachadores</option>
            </select>
            <span v-if="fieldErrors.usar_despachadores" class="mt-1 block text-sm text-red-600">
              {{ fieldErrors.usar_despachadores }}
            </span>
          </label>

          <UiAlert variant="info">
            Saldo de viajes disponible del tenant: <strong>{{ saldoTenant }}</strong>
          </UiAlert>

          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Modalidad de cobro</span>
            <select
              v-model="form.modalidad_conductores"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            >
              <option value="Prepago">Prepago (viajes comprados por adelantado)</option>
              <option value="Comision">Comisión (porcentaje por viaje entregado)</option>
            </select>
            <span v-if="fieldErrors.modalidad_conductores" class="mt-1 block text-sm text-red-600">
              {{ fieldErrors.modalidad_conductores }}
            </span>
          </label>

          <label v-if="form.modalidad_conductores === 'Prepago'" class="block">
            <span class="mb-1 block text-sm font-medium text-heading">
              Costo del viaje prepagado
            </span>
            <input
              v-model="form.costo_viaje_prepago"
              type="number"
              step="0.01"
              min="0"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
            <span v-if="fieldErrors.costo_viaje_prepago" class="mt-1 block text-sm text-red-600">
              {{ fieldErrors.costo_viaje_prepago }}
            </span>
          </label>

          <label v-else class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Porcentaje de comisión</span>
            <input
              v-model="form.comision_porcentaje"
              type="number"
              step="0.01"
              min="0"
              max="100"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
            <span v-if="fieldErrors.comision_porcentaje" class="mt-1 block text-sm text-red-600">
              {{ fieldErrors.comision_porcentaje }}
            </span>
          </label>

          <p v-if="errorConfig" role="alert" class="text-sm text-red-600">{{ errorConfig }}</p>
          <p v-if="successConfig" class="text-sm text-green-600">{{ successConfig }}</p>

          <div class="mt-2 border-t border-gray-100 pt-4">
            <button
              type="submit"
              :disabled="guardando"
              class="w-full rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
            >
              Guardar modalidad
            </button>
          </div>
        </form>

        <div v-else-if="pestanaActiva === 'zonas'">
          <UiAlert v-if="!mapService.hasApiKey()" variant="warning" class="mb-4">
            Configura <code>VITE_GOOGLE_MAPS_API_KEY</code> para poder dibujar la geocerca de cada
            zona sobre un mapa. Mientras tanto puedes administrar nombre y estado.
          </UiAlert>

          <div class="mb-6">
            <button
              v-if="mapService.hasApiKey() && !nuevaZonaAbierta"
              type="button"
              class="inline-flex items-center gap-1.5 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-heading"
              @click="abrirNuevaZona"
            >
              <Icon icon="fluent-color:pin-24" width="16" height="16" aria-hidden="true" />
              Nueva geocerca
            </button>

            <form
              v-else-if="!mapService.hasApiKey()"
              class="flex flex-wrap items-end gap-3"
              @submit.prevent="onCrearZona"
            >
              <label class="block">
                <span class="mb-1 block text-sm font-medium text-heading">Nombre de la zona</span>
                <input
                  v-model="nuevaZona.nombre"
                  type="text"
                  required
                  class="w-56 rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
                />
              </label>
              <button
                type="submit"
                :disabled="creandoZona"
                class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60"
              >
                Agregar zona
              </button>
            </form>

            <div v-else class="rounded-lg border border-gray-200 p-3">
              <p class="mb-2 text-sm text-body">
                Haz clic sobre el mapa para ir agregando los vértices de la geocerca (mínimo 3);
                arrastra un vértice para ajustarlo.
              </p>
              <div :id="NUEVA_ZONA_CONTAINER" class="h-80 w-full overflow-hidden rounded-lg" />
              <div class="mt-3 flex flex-wrap items-end gap-3">
                <label class="block">
                  <span class="mb-1 block text-sm font-medium text-heading">
                    Nombre de la zona
                  </span>
                  <input
                    v-model="nombreNuevaZona"
                    type="text"
                    required
                    class="w-56 rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
                  />
                </label>
                <button
                  type="button"
                  :disabled="creandoZona"
                  class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60"
                  @click="guardarNuevaZona"
                >
                  Guardar geocerca
                </button>
                <button
                  type="button"
                  class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-heading transition-colors hover:bg-gray-50"
                  @click="cerrarNuevaZona"
                >
                  Cancelar
                </button>
                <span class="text-sm text-body">{{ puntosNuevaZona.length }} vértices</span>
              </div>
            </div>
          </div>

          <p v-if="errorZonas" role="alert" class="mb-4 text-sm text-red-600">{{ errorZonas }}</p>

          <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm">
              <thead>
                <tr
                  class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
                >
                  <th class="py-2 pr-4">Nombre</th>
                  <th class="py-2 pr-4">Estado</th>
                  <th class="py-2 pr-4">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="loadingZonas">
                  <td colspan="3" class="py-6 text-center text-black/50">Cargando...</td>
                </tr>
                <tr v-else-if="zonas.length === 0">
                  <td colspan="3" class="py-6 text-center text-black/50">
                    No hay zonas de cobertura.
                  </td>
                </tr>
                <template v-else v-for="zona in zonas" :key="zona.id_zona">
                  <tr class="border-b border-gray-100 text-heading">
                    <td class="py-2 pr-4 font-medium">{{ zona.nombre }}</td>
                    <td class="py-2 pr-4">
                      <UiBadge
                        :text="zona.estado"
                        :color="zona.estado === 'Activo' ? 'green' : 'orange'"
                      />
                    </td>
                    <td class="py-2 pr-4">
                      <div class="flex flex-wrap gap-2">
                        <button
                          v-if="mapService.hasApiKey()"
                          type="button"
                          class="inline-flex items-center gap-1.5 rounded-lg border border-accent px-3 py-1.5 text-sm font-semibold text-accent transition-colors hover:bg-accent hover:text-white"
                          @click="abrirDibujoZona(zona)"
                        >
                          <Icon
                            icon="fluent-color:pin-24"
                            width="16"
                            height="16"
                            aria-hidden="true"
                          />
                          {{
                            zonaDibujando === zona.id_zona
                              ? 'Cerrar'
                              : zona.poligono
                                ? 'Editar geocerca'
                                : 'Dibujar geocerca'
                          }}
                        </button>
                        <button
                          type="button"
                          :disabled="togglingZonaId === zona.id_zona"
                          class="rounded-lg border border-accent px-3 py-1.5 text-sm font-semibold text-accent transition-colors hover:bg-accent hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
                          @click="onToggleEstadoZona(zona)"
                        >
                          {{ zona.estado === 'Activo' ? 'Desactivar' : 'Activar' }}
                        </button>
                        <button
                          type="button"
                          :disabled="deletingZonaId === zona.id_zona"
                          class="rounded-lg border border-red-600 px-3 py-1.5 text-sm font-semibold text-red-600 transition-colors hover:bg-red-600 hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
                          @click="requestDeleteZona(zona)"
                        >
                          Eliminar
                        </button>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="zonaDibujando === zona.id_zona" :key="`${zona.id_zona}-mapa`">
                    <td colspan="3" class="pt-2 pb-4">
                      <div class="rounded-lg border border-gray-200 p-3">
                        <p class="mb-2 text-sm text-body">
                          Haz clic sobre el mapa para ir agregando los vértices de la geocerca
                          (mínimo 3); arrastra un vértice para ajustarlo.
                        </p>
                        <div
                          :id="containerIdZona(zona.id_zona)"
                          class="h-80 w-full overflow-hidden rounded-lg"
                        />
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                          <button
                            type="button"
                            :disabled="guardandoPoligonoId === zona.id_zona"
                            class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60"
                            @click="guardarPoligonoZona(zona)"
                          >
                            Guardar geocerca
                          </button>
                          <button
                            type="button"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-heading transition-colors hover:bg-gray-50"
                            @click="cerrarDibujoZona(zona.id_zona)"
                          >
                            Cancelar
                          </button>
                          <span class="text-sm text-body">
                            {{ (puntosPoligono[zona.id_zona] ?? []).length }} vértices
                          </span>
                        </div>
                      </div>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>

        <div v-else>
          <CambiarPasswordForm :slug="slug" />
        </div>
      </template>
    </UiCard>

    <UiConfirmDialog
      :open="zonaToDelete !== null"
      title="Confirmar eliminación"
      :message="zonaToDelete ? `¿Seguro que quieres eliminar la zona ${zonaToDelete.nombre}?` : ''"
      confirm-label="Eliminar"
      @confirm="confirmDeleteZona"
      @cancel="cancelDeleteZona"
    />

    <UiConfirmDialog
      :open="mostrarConfirmDesactivarDespachadores"
      title="Dejar de utilizar despachadores"
      message="Al cambiar a 'No utilizar despachadores': todos los conductores pasarán a control directo tuyo; los despachadores existentes pasarán a estado Inactivo (no se eliminarán, sus usuarios conservan su acceso); el menú 'Despachadores' dejará de mostrarse y el Panel quedará disponible para ti. ¿Confirmas el cambio?"
      confirm-label="Sí, dejar de utilizar despachadores"
      cancel-label="Cancelar"
      @confirm="confirmarDesactivarDespachadores"
      @cancel="cancelarDesactivarDespachadores"
    />
  </TenantLayout>
</template>
