<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import CambiarPasswordForm from '@/components/tenant/CambiarPasswordForm.vue'

const route = useRoute()
const slug = computed(() => route.params.slug as string)
</script>

<template>
  <TenantLayout>
    <UiCard title="Mi cuenta">
      <CambiarPasswordForm :slug="slug" />
    </UiCard>
  </TenantLayout>
</template>
