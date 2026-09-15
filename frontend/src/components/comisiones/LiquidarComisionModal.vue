<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import http from '@/lib/http'

/**
 * Registrar un pago de comisión a un conductor (spec tenant/030, adición 8).
 *
 * Un solo modal para la lista de conductores y para la pantalla de Comisiones. Al abrirse vuelve a
 * pedir el pendiente al servidor —no confía en el número que traía la tabla— para no liquidar
 * sobre un dato viejo (supuesto 22). El tope real lo aplica el backend dentro de su bloqueo; aquí
 * solo se prefija el monto y se muestran sus errores.
 */

export interface ConductorLiquidable {
  id_conductor: number
  nombre: string
}

export interface ResumenComisiones {
  viajes: number
  importe_envios: number
  generado: number
  pagado: number
  pendiente: number
  porcentaje_actual: number
}

export interface LiquidacionRegistrada {
  id_liquidacion: number
  id_conductor: number
  monto: number
  referencia: string | null
  registrado_por: string | null
  viajes_cubiertos?: number
  created_at: string
}

const props = defineProps<{
  slug: string
  conductor: ConductorLiquidable
}>()

const emit = defineEmits<{
  cerrar: []
  liquidado: [payload: { liquidacion: LiquidacionRegistrada; resumen: ResumenComisiones }]
}>()

const formatoImporte = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' })

const form = reactive({ monto: '', referencia: '' })
const pendiente = ref<number | null>(null)
const cargando = ref(false)
const guardando = ref(false)
const error = ref('')
const exito = ref('')

const sinPendiente = computed(() => pendiente.value !== null && pendiente.value <= 0)

async function cargarPendiente() {
  cargando.value = true
  error.value = ''

  try {
    const { data } = await http.get(
      `/t/${props.slug}/conductores/${props.conductor.id_conductor}/comisiones`,
    )
    pendiente.value = data.resumen.pendiente
    form.monto = data.resumen.pendiente > 0 ? String(data.resumen.pendiente) : ''
  } catch {
    pendiente.value = null
    error.value = 'No se pudo consultar la comisión pendiente.'
  } finally {
    cargando.value = false
  }
}

async function confirmar() {
  error.value = ''
  exito.value = ''
  guardando.value = true

  try {
    const { data } = await http.post(
      `/t/${props.slug}/conductores/${props.conductor.id_conductor}/liquidaciones`,
      { monto: form.monto, referencia: form.referencia || null },
    )
    pendiente.value = data.resumen.pendiente
    exito.value = `Se registró un pago de ${formatoImporte.format(data.liquidacion.monto)} a ${props.conductor.nombre}.`
    form.monto = ''
    form.referencia = ''
    emit('liquidado', data)
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      error.value =
        (Object.values(errors)[0] as string[])?.[0] ?? 'No se pudo registrar la liquidación.'
    } else {
      error.value = 'No se pudo registrar la liquidación, intenta de nuevo.'
    }
  } finally {
    guardando.value = false
  }
}

onMounted(cargarPendiente)
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      role="dialog"
      aria-modal="true"
      aria-label="Registrar liquidación de comisión"
    >
      <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-lg shadow-black/10">
        <h2 class="text-base font-semibold text-heading">
          Liquidar comisión a {{ conductor.nombre }}
        </h2>
        <p v-if="cargando" class="mt-1 text-sm text-black/50">Consultando pendiente...</p>
        <p v-else-if="pendiente !== null" class="mt-1 text-sm text-black/60">
          Comisión pendiente: <strong>{{ formatoImporte.format(pendiente) }}</strong>
        </p>

        <label class="mt-4 block">
          <span class="mb-1 block text-sm font-medium text-heading">Monto pagado ($)</span>
          <input
            v-model="form.monto"
            type="number"
            step="0.01"
            min="0.01"
            :max="pendiente ?? undefined"
            :disabled="cargando || sinPendiente"
            placeholder="150.00"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none disabled:bg-black/5"
          />
        </label>

        <label class="mt-3 block">
          <span class="mb-1 block text-sm font-medium text-heading">Referencia / nota</span>
          <input
            v-model="form.referencia"
            type="text"
            maxlength="120"
            :disabled="cargando || sinPendiente"
            placeholder="Transferencia, efectivo, folio..."
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none disabled:bg-black/5"
          />
        </label>

        <p v-if="error" role="alert" class="mt-2 text-sm text-red-600">{{ error }}</p>
        <p v-if="exito" class="mt-2 text-sm text-green-600">{{ exito }}</p>

        <div class="mt-5 flex justify-end gap-3">
          <button
            type="button"
            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-heading hover:bg-black/5"
            @click="emit('cerrar')"
          >
            Cerrar
          </button>
          <button
            type="button"
            :disabled="guardando || cargando || sinPendiente || !form.monto"
            class="rounded-lg bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
            @click="confirmar"
          >
            Registrar pago
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
