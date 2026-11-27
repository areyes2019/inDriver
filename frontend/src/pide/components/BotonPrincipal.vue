<script setup lang="ts">
import { Icon } from '@iconify/vue'

withDefaults(
  defineProps<{
    variante?: 'morado' | 'amarillo' | 'claro' | 'texto' | 'peligro'
    cargando?: boolean
    deshabilitado?: boolean
    icono?: string
    tipo?: 'button' | 'submit'
  }>(),
  { variante: 'morado', cargando: false, deshabilitado: false, icono: undefined, tipo: 'button' },
)

const clases = {
  morado:
    'bg-morado-700 text-white shadow-flotante active:bg-morado-800 disabled:bg-morado-200 disabled:shadow-none',
  amarillo:
    'bg-amarillo-500 text-morado-950 shadow-flotante active:bg-amarillo-400 disabled:opacity-50 disabled:shadow-none',
  claro: 'bg-white text-morado-700 ring-1 ring-linea active:bg-morado-50 disabled:opacity-50',
  texto: 'bg-transparent text-morado-700 active:bg-morado-50 disabled:opacity-50',
  peligro: 'bg-white text-peligro ring-1 ring-red-200 active:bg-red-50 disabled:opacity-50',
} as const
</script>

<template>
  <button
    :type="tipo"
    :disabled="deshabilitado || cargando"
    :aria-busy="cargando"
    class="flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl px-5 text-base font-semibold transition-colors"
    :class="clases[variante]"
  >
    <Icon v-if="cargando" icon="tabler:refresh" class="size-5 animate-spin" aria-hidden="true" />
    <Icon v-else-if="icono" :icon="icono" class="size-5" aria-hidden="true" />
    <slot />
  </button>
</template>
