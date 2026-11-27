<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { computed } from 'vue'
import type { EstadoPedido } from '../api/tipos'
import { PASOS_PROGRESO, pasoDeProgreso } from '../lib/estados'

/** Línea de progreso de 5 puntos (RN-40). */
const props = defineProps<{ estado: EstadoPedido }>()
const actual = computed(() => pasoDeProgreso(props.estado))
</script>

<template>
  <ol v-if="actual !== null" class="flex items-start" aria-label="Avance del pedido">
    <li
      v-for="(nombre, i) in PASOS_PROGRESO"
      :key="nombre"
      class="relative flex flex-1 flex-col items-center"
      :aria-current="i === actual ? 'step' : undefined"
    >
      <span
        v-if="i > 0"
        class="absolute top-3 right-1/2 h-1 w-full -translate-y-1/2 rounded-full transition-colors duration-500"
        :class="i <= actual ? 'bg-morado-600' : 'bg-linea'"
        aria-hidden="true"
      />
      <span
        class="relative z-10 flex size-6 items-center justify-center rounded-full transition-colors duration-500"
        :class="
          i < actual
            ? 'bg-morado-600 text-white'
            : i === actual
              ? 'bg-amarillo-500 ring-4 ring-amarillo-100'
              : 'bg-linea'
        "
      >
        <Icon v-if="i < actual" icon="tabler:check" class="size-4" />
      </span>
      <span
        class="mt-1.5 text-center text-[0.7rem] leading-tight"
        :class="i === actual ? 'font-semibold text-tinta' : 'text-suave'"
      >
        {{ nombre }}
      </span>
    </li>
  </ol>
</template>
