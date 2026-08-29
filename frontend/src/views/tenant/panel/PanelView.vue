<script setup lang="ts">
import { ref } from 'vue'
import TenantLayout from '@/layouts/TenantLayout.vue'
import ServiciosEnTurno from '@/components/panel/ServiciosEnTurno.vue'
import NuevaEntregaPanel from '@/components/panel/NuevaEntregaPanel.vue'

const layoutRef = ref<InstanceType<typeof TenantLayout>>()
const nuevaEntregaAbierta = ref(false)

function alternarNuevaEntrega() {
  nuevaEntregaAbierta.value = !nuevaEntregaAbierta.value
}

function cerrarNuevaEntrega() {
  nuevaEntregaAbierta.value = false
  layoutRef.value?.focusNuevaEntrega()
}
</script>

<template>
  <TenantLayout
    ref="layoutRef"
    :nueva-entrega-abierta="nuevaEntregaAbierta"
    @toggle-nueva-entrega="alternarNuevaEntrega"
  >
    <!-- Mapa central, ocupa toda el área bajo el navbar: tenant/009-mapa.md -->
    <ServiciosEnTurno />
    <!-- Columna derecha (conductores activos), flotante sobre el mapa: tenant/010-drivers.md -->
    <NuevaEntregaPanel
      :abierto="nuevaEntregaAbierta"
      @cerrar="cerrarNuevaEntrega"
      @agendar="cerrarNuevaEntrega"
    />
  </TenantLayout>
</template>
