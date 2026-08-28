<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import UiNavbar from '@/components/ui/UiNavbar.vue'
import { useAdminAuthStore } from '@/stores/adminAuth'

const auth = useAdminAuthStore()
const router = useRouter()

const items = computed(() => {
  const base = [{ label: 'Dashboard', to: '/admin' }]

  if (import.meta.env.DEV) {
    base.push({ label: 'Style guide', to: '/admin/style-guide' })
  }

  return base
})

async function onLogout() {
  await auth.logout()
  router.push({ name: 'admin-login' })
}
</script>

<template>
  <div class="min-h-screen bg-black/[0.03]">
    <UiNavbar logo-text="inDriver" :items="items">
      <template #actions>
        <span v-if="auth.admin" class="text-sm text-body">
          {{ auth.admin.nombre }} {{ auth.admin.apellido_paterno }}
        </span>
        <button
          type="button"
          class="rounded-lg border border-default bg-neutral-primary px-3 py-1.5 text-sm font-medium text-heading hover:bg-black/5"
          @click="onLogout"
        >
          Cerrar sesión
        </button>
      </template>
    </UiNavbar>
    <main class="mx-auto max-w-screen-xl px-4 pb-4 pt-[5.25rem] md:px-8 md:pb-8 md:pt-[6.25rem]">
      <slot />
    </main>
  </div>
</template>
