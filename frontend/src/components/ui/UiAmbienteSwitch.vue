<script setup lang="ts">
import { computed } from 'vue'
import { useAmbienteStore } from '@/stores/ambiente'

/**
 * Interruptor TEST/LIVE de la cabecera (spec tenant/025, RN-01/RN-02), al estilo Facturapi/Stripe.
 *
 * Solo el AdminCliente puede moverlo; el Despachador ve el estado y el control deshabilitado. El
 * envío sella su ambiente al crearse, así que mover esto no altera nada de lo que ya existe.
 */
const props = defineProps<{
  slug: string
  editable: boolean
}>()

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
    class="flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-wide transition-colors disabled:cursor-default"
    :class="
      esTest
        ? 'border-amber-400 bg-amber-100 text-amber-900 enabled:hover:bg-amber-200'
        : 'border-default bg-neutral-primary text-body enabled:hover:bg-black/5'
    "
    @click="alternar"
  >
    <span
      class="inline-block h-2 w-2 rounded-full"
      :class="esTest ? 'bg-amber-500' : 'bg-emerald-500'"
    />
    {{ esTest ? 'Prueba' : 'Producción' }}
  </button>
</template>
