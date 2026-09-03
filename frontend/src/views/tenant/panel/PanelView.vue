<script setup lang="ts">
import { ref } from 'vue'
import TenantLayout from '@/layouts/TenantLayout.vue'
import ServiciosEnTurno from '@/components/panel/ServiciosEnTurno.vue'
import MapaConductores from '@/components/panel/MapaConductores.vue'
import NuevaEntregaPanel from '@/components/panel/NuevaEntregaPanel.vue'

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
    <!-- Columna central (mapa de conductores), desplazada para no quedar bajo la izquierda: tenant/009-mapa.md -->
    <div class="ml-[30%] min-h-[calc(100vh-4.25rem-2rem)]">
      <MapaConductores />
    </div>
    <!-- Panel deslizante de agendamiento rápido, se superpone a la columna izquierda al abrir: tenant/006-crud-pedidos.md -->
    <NuevaEntregaPanel
      :abierto="nuevaEntregaAbierta"
      @cerrar="cerrarNuevaEntrega"
      @agendado="onAgendado"
    />
  </TenantLayout>
</template>
