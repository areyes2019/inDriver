<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { usePanelStore } from '@/stores/panel'

/**
 * Píldora con los viajes que le quedan al tenant frente a ADMIN_CENTRAL, flotando sobre el mapa
 * (spec tenant/007, ampliación "el Panel es la vista principal"; spec tenant/027 §12).
 *
 * De solo lectura sobre el store del Panel (RN-A5): no hace ninguna petición. El número llega con
 * la carga inicial, lo reemplaza `saldo-tenant.cambiado` y lo corrige la reconciliación. En cero o
 * negativo solo cambia de color y de texto (RN-A4): no bloquea nada, el bloqueo por saldo sigue
 * siendo una historia futura.
 */
const panel = usePanelStore()

const saldo = computed(() => panel.saldoViajesTenant)
const sinViajes = computed(() => saldo.value !== null && saldo.value <= 0)
const resaltado = computed(() => panel.estaResaltado('saldo-tenant'))

const texto = computed(() => {
  if (saldo.value === null) return ''
  if (saldo.value === 0) return 'Sin viajes disponibles'
  const unidad = Math.abs(saldo.value) === 1 ? 'viaje' : 'viajes'

  return `Saldo: ${saldo.value} ${unidad}`
})
</script>

<template>
  <!-- Centrada en la franja superior del mapa, entre los dos paneles laterales (spec 007, RN-A5): mismo
       z-index que la flotilla, por debajo de la navbar y de los paneles deslizantes, que pueden taparla. -->
  <div
    class="fixed left-1/2 top-[calc(var(--alto-banda)+0.5rem)] z-30 -translate-x-1/2"
    role="status"
    aria-live="polite"
    data-testid="saldo-viajes-tenant"
  >
    <div
      v-if="saldo === null"
      class="h-8 w-40 animate-pulse rounded-full bg-white/95 shadow-md"
      aria-hidden="true"
    />
    <div
      v-else
      class="flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-semibold shadow-md transition-colors duration-300"
      :class="[
        sinViajes ? 'bg-red-100 text-red-700' : 'bg-white/95 text-heading',
        resaltado && !sinViajes ? 'bg-accent-soft' : '',
        resaltado && sinViajes ? 'bg-red-200' : '',
      ]"
    >
      <Icon
        :icon="sinViajes ? 'mdi:ticket-confirmation-outline' : 'mdi:ticket-confirmation'"
        width="18"
        height="18"
        aria-hidden="true"
        :class="sinViajes ? 'text-red-700' : 'text-accent'"
      />
      <span>{{ texto }}</span>
    </div>
  </div>
</template>
