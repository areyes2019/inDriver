<script setup lang="ts">
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import TenantLayout from '@/layouts/TenantLayout.vue'
import ServiciosEnTurno from '@/components/panel/ServiciosEnTurno.vue'
import MapaConductores from '@/components/panel/MapaConductores.vue'
import ConductoresActivos from '@/components/panel/ConductoresActivos.vue'
import NuevaEntregaPanel from '@/components/panel/NuevaEntregaPanel.vue'
import { useRealtime } from '@/composables/useRealtime'

// Deja lista la conexión de tiempo real del tenant (spec tenant/018) — sin listeners todavía,
// eso lo agregan las specs 020/021 que la heredan.
useRealtime(useRoute().params.slug as string)

const layoutRef = ref<InstanceType<typeof TenantLayout>>()
const serviciosRef = ref<InstanceType<typeof ServiciosEnTurno>>()
const nuevaEntregaAbierta = ref(false)

function alternarNuevaEntrega() {
  nuevaEntregaAbierta.value = !nuevaEntregaAbierta.value
}

function cerrarNuevaEntrega() {
  nuevaEntregaAbierta.value = false
  layoutRef.value?.focusNuevaEntrega()
}

function onAgendado() {
  serviciosRef.value?.recargar()
  cerrarNuevaEntrega()
}
</script>

<template>
  <TenantLayout
    ref="layoutRef"
    :nueva-entrega-abierta="nuevaEntregaAbierta"
    @toggle-nueva-entrega="alternarNuevaEntrega"
  >
    <!-- Columna izquierda (viajes en turno), fija sobre el 30% izquierdo: tenant/008-servicios.md, tenant/012-datos-reales-servicios-en-turno.md -->
    <ServiciosEnTurno ref="serviciosRef" />
    <!-- Columna central (mapa de conductores), centrada entre los dos paneles fijos: tenant/009-mapa.md -->
    <div class="ml-[30%] mr-[30%] min-h-[calc(100vh-4.25rem-2rem)]">
      <MapaConductores />
    </div>
    <!-- Columna derecha (conductores activos), fija sobre el 30% derecho: tenant/014-datos-reales-conductores-activos.md -->
    <ConductoresActivos />
    <!-- Panel deslizante de agendamiento rápido, se superpone a la columna izquierda al abrir: tenant/006-crud-pedidos.md -->
    <NuevaEntregaPanel
      :abierto="nuevaEntregaAbierta"
      @cerrar="cerrarNuevaEntrega"
      @agendado="onAgendado"
    />
  </TenantLayout>
</template>
