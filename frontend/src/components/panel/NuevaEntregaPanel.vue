<script setup lang="ts">
import { nextTick, reactive, ref, watch } from 'vue'

interface NuevaEntregaPayload {
  nombre_solicitante: string
  telefono_solicitante: string
  direccion_recogida: string
  direccion_entrega: string
  fecha_servicio: string
  lo_antes_posible: boolean
  hora_desde: string
  hora_hasta: string
  modalidad_pago: 'RECEPTOR_PAGA_ENVIO' | 'REMITENTE_PAGA_ENVIO' | 'RECEPTOR_PAGA_ENVIO_PRODUCTOS'
  importe_envio: string
  importe_cobro: string
}

const props = defineProps<{
  abierto: boolean
}>()

const emit = defineEmits<{
  cerrar: []
  agendar: [payload: NuevaEntregaPayload]
}>()

function formInicial(): NuevaEntregaPayload {
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

const form = reactive<NuevaEntregaPayload>(formInicial())
const horaError = ref('')

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

function onSubmit() {
  horaError.value = ''
  if (!form.lo_antes_posible) {
    if (!form.hora_desde || !form.hora_hasta) {
      horaError.value = 'Indica la hora desde y hasta, o marca "Lo antes posible".'
      return
    }
    if (form.hora_hasta <= form.hora_desde) {
      horaError.value = 'La hora hasta debe ser posterior a la hora desde.'
      return
    }
  }

  emit('agendar', { ...form })
  Object.assign(form, formInicial())
}
</script>

<template>
  <aside
    class="fixed left-0 top-0 z-[35] flex h-screen w-[30%] flex-col bg-white shadow-xl transition-transform duration-[400ms] ease-in-out"
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
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Teléfono del solicitante</span>
          <input
            v-model="form.telefono_solicitante"
            type="tel"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
        </label>
      </div>

      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Dirección de recogida</span>
        <input
          v-model="form.direccion_recogida"
          type="text"
          required
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
        />
      </label>

      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Dirección de entrega</span>
        <input
          v-model="form.direccion_entrega"
          type="text"
          required
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
        />
      </label>

      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Fecha de servicio</span>
        <input
          v-model="form.fecha_servicio"
          type="date"
          required
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
        />
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
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Hora hasta</span>
          <input
            v-model="form.hora_hasta"
            type="time"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
        </label>
      </div>

      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Modalidad de pago</span>
        <select
          v-model="form.modalidad_pago"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
        >
          <option value="RECEPTOR_PAGA_ENVIO">Receptor paga envío</option>
          <option value="REMITENTE_PAGA_ENVIO">Remitente paga envío</option>
          <option value="RECEPTOR_PAGA_ENVIO_PRODUCTOS">Receptor paga envío y productos</option>
        </select>
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
        </label>
      </div>

      <p v-if="horaError" role="alert" class="text-sm text-red-600">{{ horaError }}</p>

      <div class="flex flex-wrap gap-3 border-t border-gray-100 pt-4">
        <button
          type="submit"
          class="rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-heading focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
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
