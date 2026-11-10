<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { Icon } from '@iconify/vue'
import axios from 'axios'
import http from '@/lib/http'
import { faseDe, sePuedeCancelar, sePuedeCerrarPorIncidencia } from '@/lib/estadosPedido'
import { usePanelStore } from '@/stores/panel'
import UiConfirmDialog from '@/components/ui/UiConfirmDialog.vue'
import type { ViajeEnTurno } from '@/stores/panel'

const props = defineProps<{ viaje: ViajeEnTurno | null }>()

const emit = defineEmits<{ cerrar: []; cancelado: [] }>()

const route = useRoute()
const slug = route.params.slug as string

const confirmando = ref(false)
const cancelando = ref(false)
const errorCancelar = ref<string | null>(null)
const motivoIncidencia = ref('')
const panel = usePanelStore()

const formatoImporte = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' })

/**
 * spec tenant/027, RN-26: el detalle sigue al envío en vivo. Si termina mientras está abierto, no
 * se cierra solo —eso le arrancaría la pantalla de las manos a quien la está leyendo—: muestra en
 * qué acabó y deja de ofrecer cancelar, porque ya no hay nada que cancelar.
 */
const ESTADOS_FINALES: Record<string, { texto: string; clase: string }> = {
  ENTREGADO: { texto: faseDe('ENTREGADO'), clase: 'bg-success-bg text-success-text' },
  CANCELADO: { texto: faseDe('CANCELADO'), clase: 'bg-red-100 text-red-700' },
  INCIDENCIA: { texto: faseDe('INCIDENCIA'), clase: 'bg-red-100 text-red-700' },
  DEVUELTO: { texto: faseDe('DEVUELTO'), clase: 'bg-red-100 text-red-700' },
}

const cerrado = computed(() => (props.viaje ? (ESTADOS_FINALES[props.viaje.estado] ?? null) : null))

// Spec tenant/006: se cancela hasta En recogida; con el paquete encima solo queda la incidencia.
// spec tenant/031: lo que el repartidor le cobra al vendedor si el comprador no pagó.
const cobroAlVendedor = computed(() => {
  const viaje = props.viaje
  if (viaje === null || (viaje.estado !== 'EN_REGRESO' && viaje.estado !== 'DEVUELTO')) return null

  return (
    Number(viaje.importe_cobro ?? 0) +
    Number(viaje.importe_envio ?? 0) +
    Number(viaje.tarifa_regreso ?? 0)
  )
})

const cancelable = computed(() => (props.viaje ? sePuedeCancelar(props.viaje.estado) : false))
const porIncidencia = computed(() =>
  props.viaje ? sePuedeCerrarPorIncidencia(props.viaje.estado) : false,
)

function importeFormateado(importe: string | number | null): string {
  if (importe === null || importe === '') return '—'
  const valor = typeof importe === 'number' ? importe : Number(importe)
  return Number.isNaN(valor) ? '—' : formatoImporte.format(valor)
}

// Cambiar de viaje seleccionado no debe arrastrar el error ni el diálogo del anterior.
watch(
  () => props.viaje?.id_pedido,
  () => {
    confirmando.value = false
    errorCancelar.value = null
    motivoIncidencia.value = ''
  },
)

async function cancelarViaje() {
  const viaje = props.viaje
  if (viaje === null) return

  confirmando.value = false
  cancelando.value = true
  errorCancelar.value = null

  try {
    await http.patch(
      `/t/${slug}/pedidos/${viaje.id_pedido}/estado`,
      porIncidencia.value
        ? { estado: 'INCIDENCIA', motivo: motivoIncidencia.value.trim() }
        : { estado: 'CANCELADO', cancelado_por: 'ADMIN' },
    )
    emit('cancelado')
  } catch (err) {
    // 422: el viaje ya no se puede cancelar porque cambió de estado y esta pantalla no se enteró.
    // Reintentar daría el mismo error; lo que hace falta es poner la lista al día.
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      errorCancelar.value = 'Este viaje ya cambió de estado. Actualizamos la lista.'
      void panel.sincronizar()
    } else {
      errorCancelar.value = 'No se pudo cancelar el viaje.'
    }
  } finally {
    cancelando.value = false
  }
}

function onKeydown(event: KeyboardEvent) {
  if (event.key !== 'Escape') return
  if (props.viaje === null) return
  if (confirmando.value) {
    confirmando.value = false
    return
  }
  emit('cerrar')
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <aside
    class="fixed left-(--riel-ocupado) top-(--alto-banda) z-[35] flex h-[calc(100vh-var(--alto-banda))] w-[20%] flex-col bg-white shadow-xl transition-transform duration-[400ms] ease-in-out"
    :class="viaje ? 'translate-x-0' : '-translate-x-[calc(100%+var(--riel-ocupado))]'"
    :inert="!viaje"
    aria-label="Detalle del envío"
  >
    <header class="flex items-start justify-between gap-3 border-b border-default px-5 py-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-body">Viaje seleccionado</p>
        <h2 class="text-lg font-semibold text-heading">Detalle del envío</h2>
      </div>
      <button
        type="button"
        class="rounded-lg bg-slate-100 p-2 text-heading transition-colors hover:bg-slate-200 focus:outline-none focus:ring-2 focus:ring-accent"
        aria-label="Cerrar detalle del envío"
        @click="emit('cerrar')"
      >
        <svg
          width="16"
          height="16"
          viewBox="0 0 16 16"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          aria-hidden="true"
        >
          <path d="M3 3l10 10M13 3L3 13" />
        </svg>
      </button>
    </header>

    <div v-if="viaje" class="flex-1 overflow-y-auto">
      <!-- Ruta: la línea punteada va detrás y los dos puntos la tapan en sus extremos. -->
      <section class="relative px-5 py-4">
        <span
          class="absolute left-[25px] top-8 bottom-8 border-l-2 border-dashed border-accent"
          aria-hidden="true"
        ></span>

        <div class="relative flex gap-3">
          <span
            class="relative z-10 mt-1.5 h-3 w-3 shrink-0 rounded-full bg-green-500 ring-4 ring-white"
            aria-hidden="true"
          ></span>
          <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-body">Recogida</p>
            <p class="text-sm text-heading">{{ viaje.direccion_recogida }}</p>
          </div>
        </div>

        <div class="relative mt-4 flex gap-3">
          <span
            class="relative z-10 mt-1.5 h-3 w-3 shrink-0 rounded-full bg-red-500 ring-4 ring-white"
            aria-hidden="true"
          ></span>
          <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-body">Entrega</p>
            <p class="text-sm text-heading">{{ viaje.direccion_entrega }}</p>
          </div>
        </div>
      </section>

      <section class="border-t border-default px-5 py-4">
        <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-body">
          <Icon icon="flat-color-icons:todo-list" width="16" height="16" aria-hidden="true" />
          Datos de envío
        </p>

        <div class="mt-3 flex items-center gap-2">
          <Icon icon="flat-color-icons:businessman" width="18" height="18" aria-hidden="true" />
          <span class="text-sm text-body">Recibe:</span>
          <span class="text-sm font-semibold text-heading">
            {{ viaje.nombre_solicitante ?? '—' }}
          </span>
        </div>
        <div class="mt-2 flex items-center gap-2">
          <Icon icon="flat-color-icons:phone" width="18" height="18" aria-hidden="true" />
          <span class="text-sm text-body">Teléfono:</span>
          <span class="text-sm font-semibold text-heading">
            {{ viaje.telefono_solicitante ?? '—' }}
          </span>
        </div>
      </section>

      <!-- En qué va el envío ahora mismo: cambia solo, sin cerrar ni recargar el panel (RN-26). -->
      <section class="border-t border-default px-5 py-4">
        <div class="flex items-center justify-between gap-2">
          <span class="text-sm text-body">Estado</span>
          <span
            v-if="cerrado"
            class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
            :class="cerrado.clase"
          >
            {{ cerrado.texto }}
          </span>
          <span v-else class="text-sm font-semibold text-heading">
            {{ faseDe(viaje.estado) }}
          </span>
        </div>
        <div v-if="viaje.conductor_nombre" class="mt-2 flex items-center justify-between gap-2">
          <span class="text-sm text-body">Conductor</span>
          <span class="truncate text-sm font-semibold text-heading">
            {{ viaje.conductor_nombre }}
          </span>
        </div>
      </section>

      <section class="border-t border-default px-5 py-4">
        <div class="flex items-center justify-between">
          <span class="text-sm text-body">Envío</span>
          <span class="text-sm font-semibold text-heading">
            {{ importeFormateado(viaje.importe_envio) }}
          </span>
        </div>
        <div v-if="cobroAlVendedor !== null" class="mt-2 flex items-center justify-between">
          <span class="text-sm text-body">Cobro al vendedor por el regreso</span>
          <span class="text-sm font-semibold text-heading">
            {{ importeFormateado(cobroAlVendedor) }}
          </span>
        </div>
      </section>

      <section v-if="!cerrado" class="px-5 pb-6">
        <button
          v-if="cancelable"
          type="button"
          :disabled="cancelando"
          class="w-full rounded-lg bg-red-500 py-3 text-sm font-semibold text-white transition-colors hover:bg-red-600 disabled:cursor-not-allowed disabled:opacity-60 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
          @click="confirmando = true"
        >
          {{ cancelando ? 'Cancelando...' : 'Cancelar viaje' }}
        </button>

        <template v-else-if="porIncidencia">
          <label for="motivo-incidencia" class="mb-1 block text-sm font-semibold text-heading">
            Motivo de la incidencia
          </label>
          <p class="mb-2 text-xs text-body">
            El conductor ya lleva el paquete. Usa esto solo en una emergencia.
          </p>
          <textarea
            id="motivo-incidencia"
            v-model="motivoIncidencia"
            rows="3"
            maxlength="120"
            class="mb-3 w-full rounded-lg border border-default px-3 py-2 text-sm text-heading focus:outline-none focus:ring-2 focus:ring-red-500"
          />
          <button
            type="button"
            :disabled="cancelando || motivoIncidencia.trim() === ''"
            class="w-full rounded-lg bg-red-500 py-3 text-sm font-semibold text-white transition-colors hover:bg-red-600 disabled:cursor-not-allowed disabled:opacity-60 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
            @click="confirmando = true"
          >
            {{ cancelando ? 'Cerrando...' : 'Cerrar por incidencia' }}
          </button>
        </template>

        <div v-if="errorCancelar" class="mt-3 flex flex-col items-start gap-1">
          <p class="text-sm text-red-600">{{ errorCancelar }}</p>
          <button
            type="button"
            class="text-sm font-semibold text-heading underline"
            @click="confirmando = true"
          >
            Reintentar
          </button>
        </div>
      </section>
    </div>

    <UiConfirmDialog
      :open="confirmando && viaje !== null"
      :title="porIncidencia ? 'Cerrar por incidencia' : 'Cancelar viaje'"
      :message="
        !viaje
          ? ''
          : porIncidencia
            ? `El viaje #${viaje.numero_pedido} saldrá del panel y quedará como INCIDENCIA. Esta acción no se puede deshacer.`
            : `El viaje #${viaje.numero_pedido} saldrá del panel y quedará como CANCELADO. Esta acción no se puede deshacer.`
      "
      :confirm-label="porIncidencia ? 'Sí, cerrar' : 'Sí, cancelar'"
      cancel-label="No, volver"
      @confirm="cancelarViaje"
      @cancel="confirmando = false"
    />
  </aside>
</template>
