<script setup lang="ts">
import type { EstadoConexion } from '@/services/realtime'

/**
 * Estado del tiempo real de un panel (spec tenant/027, RN-18 y RN-20).
 *
 * Son dos piezas de la misma idea: un punto junto al título que dice si lo que se ve viene llegando
 * o es de hace rato, y una franja que aparece solo cuando algo va mal.
 *
 * La franja **no reemplaza a la lista**: se pone encima. Antes un 429 pasajero borraba los envíos
 * de la pantalla y dejaba un "Reintentar" como única salida; la operación se quedaba sin panel por
 * un fallo que se corrige solo.
 */
const props = defineProps<{
  estado: EstadoConexion
  /** Hubo un fallo de red y lo que está en pantalla puede estar viejo. */
  desincronizado: boolean
  /** Como `punto`, solo pinta el indicador; si no, pinta la franja. */
  punto?: boolean
}>()

defineEmits<{ actualizar: [] }>()

const COLORES: Record<EstadoConexion, string> = {
  vivo: 'bg-emerald-500',
  conectando: 'bg-amber-400',
  caido: 'bg-slate-400',
}

const TITULOS: Record<EstadoConexion, string> = {
  vivo: 'Actualizando en vivo',
  conectando: 'Conectando…',
  caido: 'Sin conexión en vivo',
}

function hayProblema(): boolean {
  return props.desincronizado || props.estado === 'caido'
}
</script>

<template>
  <span
    v-if="punto"
    class="inline-block h-2 w-2 shrink-0 rounded-full transition-colors"
    :class="[COLORES[estado], estado === 'conectando' ? 'animate-pulse' : '']"
    :title="TITULOS[estado]"
    :aria-label="TITULOS[estado]"
    role="status"
  ></span>

  <div
    v-else-if="hayProblema()"
    class="flex items-center justify-between gap-2 border-b border-amber-200 bg-amber-50 px-4 py-2"
    role="status"
  >
    <p class="min-w-0 truncate text-xs text-amber-900">
      {{ estado === 'caido' ? 'Sin conexión — reconectando…' : 'Reintentando actualizar…' }}
    </p>
    <button
      type="button"
      class="shrink-0 text-xs font-semibold text-amber-900 underline hover:no-underline focus:outline-none focus:ring-2 focus:ring-amber-500"
      @click="$emit('actualizar')"
    >
      Actualizar
    </button>
  </div>
</template>
