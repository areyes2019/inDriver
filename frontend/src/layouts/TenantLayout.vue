<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import UiNavbar from '@/components/ui/UiNavbar.vue'
import { useTenantAuthStore } from '@/stores/tenantAuth'

const route = useRoute()
const router = useRouter()
const auth = useTenantAuthStore()

const slug = computed(() => route.params.slug as string)

const items = computed(() => [
  ...(auth.usuario?.rol === 'Despachador'
    ? [{ label: 'Panel', to: `/t/${slug.value}/panel` }]
    : []),
  { label: 'Pedidos', to: `/t/${slug.value}/panel/pedidos` },
  { label: 'Clientes', to: `/t/${slug.value}/panel/clientes` },
  { label: 'Usuarios', to: `/t/${slug.value}/panel/usuarios` },
  { label: 'Despachadores', to: `/t/${slug.value}/panel/despachadores` },
  { label: 'Conductores', to: `/t/${slug.value}/panel/conductores` },
  { label: 'Vehículos', to: `/t/${slug.value}/panel/vehiculos` },
  { label: 'Asignaciones', to: `/t/${slug.value}/panel/asignaciones` },
])

async function onLogout() {
  await auth.logout()
  router.push({ name: 'tenant-login', params: { slug: slug.value } })
}
</script>

<template>
  <div class="min-h-screen bg-black/[0.03]">
    <UiNavbar logo-text="inDriver" :items="items">
      <template #actions>
        <span v-if="auth.usuario" class="text-sm text-body">
          {{ auth.usuario.nombre }} {{ auth.usuario.apellido_paterno }}
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
