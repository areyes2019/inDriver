<script setup lang="ts">
import { useAdminAuthStore } from '@/stores/adminAuth'
import AdminLayout from '@/layouts/AdminLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'

const auth = useAdminAuthStore()
</script>

<template>
  <AdminLayout>
    <UiCard title="Panel de ADMIN_CENTRAL">
      <p v-if="auth.admin" class="text-sm text-black/70">
        Sesión iniciada como {{ auth.admin.nombre }} {{ auth.admin.apellido_paterno }}.
      </p>
      <div class="mt-4 flex flex-wrap gap-3">
        <RouterLink
          :to="{ name: 'admin-tenants-lista' }"
          class="rounded-xl bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-heading"
        >
          Ver tenants
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-tenants-crear' }"
          class="rounded-xl bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-heading"
        >
          Crear tenant
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-paquetes-lista' }"
          class="rounded-xl bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-heading"
        >
          Paquetes de viajes
        </RouterLink>
      </div>
    </UiCard>
  </AdminLayout>
</template>
