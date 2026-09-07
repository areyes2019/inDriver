<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { Icon } from '@iconify/vue'
import http from '@/lib/http'
import UiConfirmDialog from '@/components/ui/UiConfirmDialog.vue'
import type { ViajeEnTurno } from '@/components/panel/ServiciosEnTurno.vue'

const props = defineProps<{ viaje: ViajeEnTurno | null }>()

const emit = defineEmits<{ cerrar: []; cancelado: [] }>()

const route = useRoute()
const slug = route.params.slug as string

const confirmando = ref(false)
const cancelando = ref(false)
const errorCancelar = ref(false)

const formatoImporte = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' })

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
    errorCancelar.value = false
  },
)

async function cancelarViaje() {
  const viaje = props.viaje
  if (viaje === null) return

  confirmando.value = false
  cancelando.value = true
  errorCancelar.value = false

  try {
    await http.patch(`/t/${slug}/pedidos/${viaje.id_pedido}/estado`, {
      estado: 'CANCELADO',
      cancelado_por: 'ADMIN',
    })
    emit('cancelado')
  } catch {
    errorCancelar.value = true
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
    class="fixed left-0 top-[4.25rem] z-[35] flex h-[calc(100vh-4.25rem)] w-[20%] flex-col bg-white shadow-xl transition-transform duration-[400ms] ease-in-out"
    :class="viaje ? 'translate-x-0' : '-translate-x-full'"
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

      <section class="border-t border-default px-5 py-4">
        <div class="flex items-center justify-between">
          <span class="text-sm text-body">Envío</span>
          <span class="text-sm font-semibold text-heading">
            {{ importeFormateado(viaje.importe_envio) }}
          </span>
        </div>
      </section>

      <section class="px-5 pb-6">
        <button
          type="button"
          :disabled="cancelando"
          class="w-full rounded-lg bg-red-500 py-3 text-sm font-semibold text-white transition-colors hover:bg-red-600 disabled:cursor-not-allowed disabled:opacity-60 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
          @click="confirmando = true"
        >
          {{ cancelando ? 'Cancelando...' : 'Cancelar viaje' }}
        </button>

        <div v-if="errorCancelar" class="mt-3 flex flex-col items-start gap-1">
          <p class="text-sm text-red-600">No se pudo cancelar el viaje.</p>
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
      title="Cancelar viaje"
      :message="
        viaje
          ? `El viaje #${viaje.numero_pedido} saldrá del panel y quedará como CANCELADO. Esta acción no se puede deshacer.`
          : ''
      "
      confirm-label="Sí, cancelar"
      cancel-label="No, volver"
      @confirm="cancelarViaje"
      @cancel="confirmando = false"
    />
  </aside>
</template>
