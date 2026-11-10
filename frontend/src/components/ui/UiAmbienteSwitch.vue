<script setup lang="ts">
import { computed } from 'vue'
import { useAmbienteStore } from '@/stores/ambiente'

/**
 * Interruptor TEST/LIVE del pie del menú lateral (spec tenant/025, RN-01/RN-02, y tenant/032), al
 * estilo Facturapi/Stripe.
 *
 * Solo el AdminCliente puede moverlo; el Despachador ve el estado y el control deshabilitado. El
 * envío sella su ambiente al crearse, así que mover esto no altera nada de lo que ya existe.
 *
 * `compacto` es la versión del riel cerrado: no cabe "Producción", así que dice TEST o LIVE.
 */
const props = withDefaults(
  defineProps<{
    slug: string
    editable: boolean
    compacto?: boolean
  }>(),
  { compacto: false },
)

const ambientes = useAmbienteStore()

const esTest = computed(() => ambientes.esTest)

function alternar() {
  if (!props.editable) {
    return
  }

  ambientes.cambiar(props.slug, esTest.value ? 'live' : 'test')
}
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="esTest"
    :aria-label="`Ambiente actual: ${esTest ? 'prueba' : 'producción'}`"
    :disabled="!editable || ambientes.guardando"
    :title="
      editable
        ? 'Cambiar entre modo prueba y producción'
        : 'Solo el administrador puede cambiar de ambiente'
    "
    class="flex items-center rounded-full border font-semibold uppercase tracking-wide transition-colors disabled:cursor-default"
    :class="[
      compacto ? 'gap-1 px-1.5 py-0.5 text-[10px]' : 'gap-2 px-3 py-1 text-xs',
      esTest
        ? 'border-amber-400 bg-amber-100 text-amber-900 enabled:hover:bg-amber-200'
        : 'border-default bg-neutral-primary text-body enabled:hover:bg-black/5',
    ]"
    @click="alternar"
  >
    <span
      class="inline-block h-2 w-2 shrink-0 rounded-full"
      :class="esTest ? 'bg-amber-500' : 'bg-emerald-500'"
    />
    <template v-if="compacto">{{ esTest ? 'Test' : 'Live' }}</template>
    <template v-else>{{ esTest ? 'Prueba' : 'Producción' }}</template>
  </button>
</template>
