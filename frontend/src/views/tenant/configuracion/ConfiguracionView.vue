<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiAlert from '@/components/ui/UiAlert.vue'
import UiConfirmDialog from '@/components/ui/UiConfirmDialog.vue'

interface ZonaServicio {
  id_zona: number
  nombre: string
  descripcion: string | null
  estado: string
  poligono: Array<{ lat: number; lng: number }> | null
}

const route = useRoute()
const slug = computed(() => route.params.slug as string)

type Pestana = 'tarifas' | 'comision' | 'zonas'
const pestanaActiva = ref<Pestana>('tarifas')
const pestanas: Array<{ id: Pestana; label: string }> = [
  { id: 'tarifas', label: 'Tarifas' },
  { id: 'comision', label: 'Comisión / Prepago' },
  { id: 'zonas', label: 'Zonas de cobertura' },
]

// --- Tarifas + Comisión/Prepago ---

const form = reactive({
  tarifa_banderazo: '0',
  tarifa_km_adicional: '0',
  modalidad_conductores: 'Prepago' as 'Prepago' | 'Comision',
  costo_viaje_prepago: '0',
  comision_porcentaje: '0',
})

const saldoTenant = ref(0)
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
    form.tarifa_km_adicional = data.tarifa_km_adicional ?? '0'
    form.modalidad_conductores = data.modalidad_conductores ?? 'Prepago'
    form.costo_viaje_prepago = data.costo_viaje_prepago ?? '0'
    form.comision_porcentaje = data.comision_porcentaje ?? '0'
    saldoTenant.value = data.saldo_viajes_tenant ?? 0
  } catch {
    errorConfig.value = 'No se pudo cargar la configuración.'
  } finally {
    loadingConfig.value = false
  }
}

async function onSubmitConfiguracion() {
  errorConfig.value = ''
  successConfig.value = ''
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  guardando.value = true

  try {
    const { data } = await http.put(`/t/${slug.value}/configuracion`, form)
    saldoTenant.value = data.saldo_viajes_tenant ?? saldoTenant.value
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
const nuevaZona = reactive({ nombre: '', descripcion: '' })
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

async function onCrearZona() {
  if (!nuevaZona.nombre.trim()) return

  creandoZona.value = true
  errorZonas.value = ''

  try {
    const { data } = await http.post(`/t/${slug.value}/zonas-cobertura`, nuevaZona)
    zonas.value.push(data.data ?? data)
    nuevaZona.nombre = ''
    nuevaZona.descripcion = ''
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

onMounted(() => {
  fetchConfiguracion()
  fetchZonas()
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

        <div v-else>
          <UiAlert variant="warning" class="mb-4">
            El dibujo del polígono sobre un mapa todavía no está disponible (depende de
            <code>MapService</code>/<code>GoogleProvider</code>, specs 012-014, aún no
            implementadas). Por ahora solo se administra el nombre y estado de cada zona.
          </UiAlert>

          <form class="mb-6 flex flex-wrap items-end gap-3" @submit.prevent="onCrearZona">
            <label class="block">
              <span class="mb-1 block text-sm font-medium text-heading">Nombre de la zona</span>
              <input
                v-model="nuevaZona.nombre"
                type="text"
                required
                class="w-56 rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
              />
            </label>
            <label class="block">
              <span class="mb-1 block text-sm font-medium text-heading">Descripción</span>
              <input
                v-model="nuevaZona.descripcion"
                type="text"
                class="w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
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

          <p v-if="errorZonas" role="alert" class="mb-4 text-sm text-red-600">{{ errorZonas }}</p>

          <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm">
              <thead>
                <tr
                  class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
                >
                  <th class="py-2 pr-4">Nombre</th>
                  <th class="py-2 pr-4">Descripción</th>
                  <th class="py-2 pr-4">Estado</th>
                  <th class="py-2 pr-4">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="loadingZonas">
                  <td colspan="4" class="py-6 text-center text-black/50">Cargando...</td>
                </tr>
                <tr v-else-if="zonas.length === 0">
                  <td colspan="4" class="py-6 text-center text-black/50">
                    No hay zonas de cobertura.
                  </td>
                </tr>
                <tr
                  v-for="zona in zonas"
                  v-else
                  :key="zona.id_zona"
                  class="border-b border-gray-100 text-heading"
                >
                  <td class="py-2 pr-4 font-medium">{{ zona.nombre }}</td>
                  <td class="py-2 pr-4">{{ zona.descripcion ?? '—' }}</td>
                  <td class="py-2 pr-4">
                    <span
                      class="rounded-full px-2 py-0.5 text-xs font-semibold"
                      :class="
                        zona.estado === 'Activo'
                          ? 'bg-green-100 text-green-700'
                          : 'bg-orange-100 text-orange-700'
                      "
                    >
                      {{ zona.estado }}
                    </span>
                  </td>
                  <td class="py-2 pr-4">
                    <div class="flex flex-wrap gap-2">
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
              </tbody>
            </table>
          </div>
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
  </TenantLayout>
</template>
