<script setup lang="ts">
import TenantLayout from '@/layouts/TenantLayout.vue'
import ServiciosEnTurno from '@/components/panel/ServiciosEnTurno.vue'
</script>

<template>
  <TenantLayout>
    <!-- Mapa central, ocupa toda el área bajo el navbar: tenant/009-mapa.md -->
    <ServiciosEnTurno />
    <!-- Columna derecha (conductores activos), flotante sobre el mapa: tenant/010-drivers.md -->
  </TenantLayout>
</template>
