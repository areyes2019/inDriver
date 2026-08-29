<script setup lang="ts">
import { computed } from 'vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import { viajesEnTurnoFixture } from '@/fixtures/panelDespachador'

const estadoColor: Record<string, 'gray' | 'blue' | 'orange' | 'green' | 'red'> = {
  PENDIENTE: 'gray',
  PUBLICADO: 'blue',
  TOMADO: 'orange',
  ARRIBADO: 'orange',
  EN_CAMINO: 'blue',
  ARRIBADO_A_ENTREGA: 'blue',
}

const viajes = computed(() => {
  const loAntesPosible = viajesEnTurnoFixture.filter((viaje) => viaje.lo_antes_posible)
  const conHora = viajesEnTurnoFixture
    .filter((viaje) => !viaje.lo_antes_posible)
    .slice()
    .sort((a, b) => (a.hora_desde ?? '').localeCompare(b.hora_desde ?? ''))
  return [...loAntesPosible, ...conHora]
})
</script>

<template>
  <aside
    class="fixed left-0 top-[4.25rem] z-30 flex h-[calc(100vh-4.25rem)] w-[30%] flex-col bg-white shadow-xl"
  >
    <header class="border-b border-default px-5 py-4">
      <h2 class="text-base font-semibold text-heading">Viajes en turno</h2>
    </header>

    <div class="flex-1 overflow-y-auto p-4">
      <p v-if="viajes.length === 0" class="text-sm text-body">No hay viajes en turno</p>
      <ul v-else class="flex flex-col gap-3">
        <li v-for="viaje in viajes" :key="viaje.numero_pedido" class="border border-default p-3">
          <div class="flex items-center justify-between gap-2">
            <span class="text-sm font-semibold text-heading">{{ viaje.numero_pedido }}</span>
            <UiBadge :text="viaje.estado" :color="estadoColor[viaje.estado] ?? 'gray'" />
          </div>
          <p class="truncate text-sm text-body">
            {{ viaje.cliente_nombre ?? 'Solicitante ocasional' }}
          </p>
          <p class="truncate text-xs text-body/70">{{ viaje.direccion_entrega }}</p>
          <p class="text-xs text-body/70">
            {{ viaje.lo_antes_posible ? 'Lo antes posible' : viaje.hora_desde }}
          </p>
        </li>
      </ul>
    </div>
  </aside>
</template>
