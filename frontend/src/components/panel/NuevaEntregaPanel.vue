<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import UiAddressAutocomplete from '@/components/ui/UiAddressAutocomplete.vue'
import UiVistaPreviaRuta from '@/components/ui/UiVistaPreviaRuta.vue'
import UiModalidadPagoSelector, {
  type ModalidadPago,
} from '@/components/ui/UiModalidadPagoSelector.vue'
import { useTenantAuthStore } from '@/stores/tenantAuth'
import type { LatLngLike } from '@/services/maps/types'

interface NuevaEntregaForm {
  nombre_solicitante: string
  telefono_solicitante: string
  direccion_recogida: string
  direccion_entrega: string
  fecha_servicio: string
  lo_antes_posible: boolean
  hora_desde: string
  hora_hasta: string
  modalidad_pago: ModalidadPago
  importe_envio: string
  importe_cobro: string
}

const props = defineProps<{
  abierto: boolean
}>()

const emit = defineEmits<{
  cerrar: []
  agendado: []
}>()

const route = useRoute()
const slug = route.params.slug as string
const tenantAuth = useTenantAuthStore()
const coberturaBounds = computed(() => tenantAuth.usuario?.cobertura_bounds ?? null)

function formInicial(): NuevaEntregaForm {
  return {
    nombre_solicitante: '',
    telefono_solicitante: '',
    direccion_recogida: '',
    direccion_entrega: '',
    fecha_servicio: '',
    lo_antes_posible: true,
    hora_desde: '',
    hora_hasta: '',
    modalidad_pago: 'RECEPTOR_PAGA_ENVIO',
    importe_envio: '0',
    importe_cobro: '0',
  }
}

const form = reactive<NuevaEntregaForm>(formInicial())
const fieldErrors = reactive<Record<string, string>>({})
const error = ref('')
const loading = ref(false)

const recogidaCoord = ref<LatLngLike | null>(null)
const entregaCoord = ref<LatLngLike | null>(null)

function onSeleccionaRecogida(p: { lat: number | null; lng: number | null }) {
  recogidaCoord.value = p.lat !== null && p.lng !== null ? { lat: p.lat, lng: p.lng } : null
}

function onSeleccionaEntrega(p: { lat: number | null; lng: number | null }) {
  entregaCoord.value = p.lat !== null && p.lng !== null ? { lat: p.lat, lng: p.lng } : null
}

const primerCampoRef = ref<HTMLInputElement>()

watch(
  () => props.abierto,
  (visible) => {
    if (visible) {
      nextTick(() => primerCampoRef.value?.focus())
    }
  },
)

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    event.preventDefault()
    emit('cerrar')
  }
}

function limpiarFormulario() {
  Object.assign(form, formInicial())
  recogidaCoord.value = null
  entregaCoord.value = null
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  error.value = ''
}

async function onSubmit() {
  error.value = ''
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])

  if (!form.lo_antes_posible) {
    if (!form.hora_desde || !form.hora_hasta) {
      fieldErrors.hora_desde = 'Indica la hora desde y hasta, o marca "Lo antes posible".'
      return
    }
    if (form.hora_hasta <= form.hora_desde) {
      fieldErrors.hora_hasta = 'La hora hasta debe ser posterior a la hora desde.'
      return
    }
  }

  loading.value = true
  try {
    await http.post(`/t/${slug}/pedidos`, { ...form })
    limpiarFormulario()
    emit('agendado')
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      for (const [field, messages] of Object.entries(errors)) {
        fieldErrors[field] = (messages as string[])[0] ?? ''
      }
    } else {
      error.value = 'No se pudo agendar el pedido, intenta de nuevo.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <aside
    class="fixed left-0 top-0 z-[35] flex h-screen w-[45%] flex-col bg-white shadow-xl transition-transform duration-[400ms] ease-in-out"
    :class="abierto ? 'translate-x-0' : '-translate-x-full'"
    @keydown="onKeydown"
  >
    <header class="border-b border-default px-5 pb-4 pt-[4.25rem]">
      <h2 class="text-base font-semibold text-heading">Nueva Entrega</h2>
    </header>

    <form class="flex-1 space-y-4 overflow-y-auto p-4" @submit.prevent="onSubmit">
      <div class="grid grid-cols-1 gap-4">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Nombre del solicitante</span>
          <input
            ref="primerCampoRef"
            v-model="form.nombre_solicitante"
            type="text"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.nombre_solicitante" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.nombre_solicitante }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Teléfono del solicitante</span>
          <input
            v-model="form.telefono_solicitante"
            type="tel"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.telefono_solicitante" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.telefono_solicitante }}
          </span>
        </label>
      </div>

      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Dirección de recogida</span>
        <UiAddressAutocomplete
          v-model="form.direccion_recogida"
          required
          :bounds="coberturaBounds"
          @select="onSeleccionaRecogida"
        />
        <span v-if="fieldErrors.direccion_recogida" class="mt-1 block text-sm text-red-600">
          {{ fieldErrors.direccion_recogida }}
        </span>
      </label>

      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Dirección de entrega</span>
        <UiAddressAutocomplete
          v-model="form.direccion_entrega"
          required
          :bounds="coberturaBounds"
          @select="onSeleccionaEntrega"
        />
        <span v-if="fieldErrors.direccion_entrega" class="mt-1 block text-sm text-red-600">
          {{ fieldErrors.direccion_entrega }}
        </span>
      </label>

      <UiVistaPreviaRuta :origen="recogidaCoord" :destino="entregaCoord" />

      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Fecha de servicio</span>
        <input
          v-model="form.fecha_servicio"
          type="date"
          required
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
        />
        <span v-if="fieldErrors.fecha_servicio" class="mt-1 block text-sm text-red-600">
          {{ fieldErrors.fecha_servicio }}
        </span>
      </label>

      <label class="flex items-center gap-2">
        <input v-model="form.lo_antes_posible" type="checkbox" class="rounded border-gray-300" />
        <span class="text-sm font-medium text-heading">Lo antes posible</span>
      </label>

      <div v-if="!form.lo_antes_posible" class="grid grid-cols-1 gap-4">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Hora desde</span>
          <input
            v-model="form.hora_desde"
            type="time"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.hora_desde" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.hora_desde }}
          </span>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Hora hasta</span>
          <input
            v-model="form.hora_hasta"
            type="time"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.hora_hasta" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.hora_hasta }}
          </span>
        </label>
      </div>

      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Modalidad de pago</span>
        <UiModalidadPagoSelector v-model="form.modalidad_pago" />
        <span v-if="fieldErrors.modalidad_pago" class="mt-1 block text-sm text-red-600">
          {{ fieldErrors.modalidad_pago }}
        </span>
      </label>

      <div class="grid grid-cols-1 gap-4">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Importe de envío</span>
          <input
            v-model="form.importe_envio"
            type="number"
            step="0.01"
            min="0"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.importe_envio" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.importe_envio }}
          </span>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Importe de cobro</span>
          <input
            v-model="form.importe_cobro"
            type="number"
            step="0.01"
            min="0"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.importe_cobro" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.importe_cobro }}
          </span>
        </label>
      </div>

      <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>

      <div class="flex flex-wrap gap-3 border-t border-gray-100 pt-4">
        <button
          type="submit"
          :disabled="loading"
          class="rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
        >
          Agendar
        </button>
        <button
          type="button"
          class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-heading transition-colors hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
          @click="emit('cerrar')"
        >
          Cancelar
        </button>
      </div>
    </form>
  </aside>
</template>
