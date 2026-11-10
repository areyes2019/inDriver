<script setup lang="ts">
/**
 * Pantalla de Conversaciones (spec tenant/031, etapa 7b): la bandeja de WhatsApp a pantalla
 * completa, con lista y chat lado a lado. La misma bandeja vive también en el panel de chat del
 * Panel (spec tenant/032, paso 3); toda su lógica está en `BandejaWhatsapp`.
 */
import TenantLayout from '@/layouts/TenantLayout.vue'
import BandejaWhatsapp from '@/components/whatsapp/BandejaWhatsapp.vue'
</script>

<template>
  <TenantLayout>
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-heading">Conversaciones</h1>
      <p class="text-sm text-body">Lo que los clientes escriben al WhatsApp de la flotilla.</p>
    </div>

    <div class="h-[calc(100vh-13rem)] min-h-[28rem]">
      <BandejaWhatsapp />
    </div>
  </TenantLayout>
</template>
