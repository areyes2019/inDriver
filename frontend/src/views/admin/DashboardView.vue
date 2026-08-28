<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAdminAuthStore } from '@/stores/adminAuth'
import AdminLayout from '@/layouts/AdminLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'

const auth = useAdminAuthStore()
const router = useRouter()

async function onLogout() {
  await auth.logout()
  router.push({ name: 'admin-login' })
}
</script>

<template>
  <AdminLayout>
    <UiCard title="Panel de ADMIN_CENTRAL">
      <p v-if="auth.admin" class="text-sm text-black/70">
        Sesión iniciada como {{ auth.admin.nombre }} {{ auth.admin.apellido_paterno }}.
      </p>
      <button
        type="button"
        class="mt-4 rounded-xl bg-brand-dark px-4 py-2 text-sm font-semibold text-white hover:bg-brand-blue"
        @click="onLogout"
      >
        Cerrar sesión
      </button>
    </UiCard>
  </AdminLayout>
</template>
