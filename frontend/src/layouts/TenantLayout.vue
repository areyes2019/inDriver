<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { UserCog, Users } from '@lucide/vue'
import UiSidebar from '@/components/ui/UiSidebar.vue'
import { useTenantAuthStore } from '@/stores/tenantAuth'

const route = useRoute()
const router = useRouter()
const auth = useTenantAuthStore()

const slug = computed(() => route.params.slug as string)

const items = computed(() => [
  { label: 'Clientes', to: `/t/${slug.value}/panel/clientes`, icon: Users },
  { label: 'Usuarios', to: `/t/${slug.value}/panel/usuarios`, icon: UserCog },
])

async function onLogout() {
  await auth.logout()
  router.push({ name: 'tenant-login', params: { slug: slug.value } })
}
</script>

<template>
  <div class="flex h-screen overflow-hidden bg-black/[0.03]">
    <UiSidebar logo-text="inDriver" :items="items" />
    <main class="min-w-0 flex-1 overflow-y-auto p-4 pt-20 md:p-8">
      <div class="mb-4 flex justify-end">
        <button
          type="button"
          class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-brand-dark hover:bg-black/5"
          @click="onLogout"
        >
          Cerrar sesión
        </button>
      </div>
      <slot />
    </main>
  </div>
</template>
