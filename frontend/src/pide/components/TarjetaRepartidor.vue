<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { computed } from 'vue'
import { iniciales } from '@/lib/iniciales'
import { colorDeLetra } from '@/services/maps/globoConductor'
import type { Repartidor } from '../api/tipos'

/** Repartidor con su globo de iniciales (spec tenant/033) y el botón Llamar (RN-44). */
const props = defineProps<{ repartidor: Repartidor; minutos: number | null; numero: string }>()
const letra = computed(() => colorDeLetra(props.repartidor.color))
</script>

<template>
  <div class="flex items-center gap-3">
    <span
      class="flex size-14 shrink-0 items-center justify-center rounded-full font-titulo text-xl font-bold"
      :style="{ backgroundColor: repartidor.color, color: letra }"
      aria-hidden="true"
    >
      {{ iniciales(repartidor.nombre) }}
    </span>
    <div class="min-w-0 flex-1">
      <p class="truncate font-semibold text-tinta">{{ repartidor.nombre }}</p>
      <p class="text-sm text-suave">
        <template v-if="minutos !== null"
          >Llega en <strong class="text-tinta">~{{ minutos }} min</strong> ·
        </template>
        <span class="whitespace-nowrap">{{ numero }}</span>
      </p>
    </div>
    <a
      :href="`tel:${repartidor.telefono}`"
      class="flex size-14 shrink-0 items-center justify-center rounded-full bg-exito text-white shadow-tarjeta active:opacity-80"
      :aria-label="`Llamar a ${repartidor.nombre}`"
    >
      <Icon icon="tabler:phone" class="size-7" />
    </a>
  </div>
</template>
