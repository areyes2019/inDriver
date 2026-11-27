<script setup lang="ts">
import { Icon } from '@iconify/vue'
import type { ModalidadPago } from '../api/tipos'
import { MODALIDADES } from '../lib/formato'

/** Las tres modalidades del Panel, como tarjetas con ícono (D8). */
const modalidad = defineModel<ModalidadPago | null>({ required: true })

const iconos: Record<ModalidadPago, string> = {
  REMITENTE_PAGA_ENVIO: 'tabler:package-export',
  RECEPTOR_PAGA_ENVIO: 'tabler:package-import',
  RECEPTOR_PAGA_ENVIO_PRODUCTOS: 'tabler:cash',
}
</script>

<template>
  <div role="radiogroup" aria-label="¿Quién paga?" class="space-y-2.5">
    <button
      v-for="m in MODALIDADES"
      :key="m.valor"
      type="button"
      role="radio"
      :aria-checked="modalidad === m.valor"
      class="flex w-full items-center gap-3 rounded-2xl bg-white p-4 text-left ring-1 transition-all"
      :class="
        modalidad === m.valor
          ? 'ring-2 ring-morado-600 shadow-tarjeta'
          : 'ring-linea active:bg-morado-50'
      "
      @click="modalidad = m.valor"
    >
      <span
        class="flex size-12 shrink-0 items-center justify-center rounded-2xl"
        :class="modalidad === m.valor ? 'bg-morado-700 text-white' : 'bg-morado-50 text-morado-700'"
      >
        <Icon :icon="iconos[m.valor]" class="size-6" />
      </span>
      <span class="min-w-0">
        <span class="block font-semibold text-tinta">{{ m.titulo }}</span>
        <span class="block text-sm text-suave">{{ m.detalle }}</span>
      </span>
      <span
        class="ml-auto flex size-6 shrink-0 items-center justify-center rounded-full border-2"
        :class="modalidad === m.valor ? 'border-morado-700 bg-morado-700' : 'border-linea'"
      >
        <Icon v-if="modalidad === m.valor" icon="tabler:check" class="size-4 text-white" />
      </span>
    </button>
  </div>
</template>
