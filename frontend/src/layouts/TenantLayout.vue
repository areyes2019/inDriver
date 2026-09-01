<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import UiNavbar from '@/components/ui/UiNavbar.vue'
import { useTenantAuthStore } from '@/stores/tenantAuth'

const props = withDefaults(
  defineProps<{
    nuevaEntregaAbierta?: boolean
  }>(),
  {
    nuevaEntregaAbierta: false,
  },
)

const emit = defineEmits<{
  'toggle-nueva-entrega': []
}>()

const route = useRoute()
const router = useRouter()
const auth = useTenantAuthStore()

const slug = computed(() => route.params.slug as string)
const enPanel = computed(() => route.name === 'tenant-panel')
const esDespachador = computed(() => auth.usuario?.rol === 'Despachador')
const mostrarNuevaEntrega = computed(() => enPanel.value && esDespachador.value)

const botonNuevaEntregaRef = ref<HTMLButtonElement>()

onMounted(() => {
  if (mostrarNuevaEntrega.value) {
    nextTick(() => botonNuevaEntregaRef.value?.focus())
  }
})

function onNuevaEntregaKeydown(event: KeyboardEvent) {
  if ((event.key === 'ArrowRight' || event.key === 'ArrowDown') && !props.nuevaEntregaAbierta) {
    event.preventDefault()
    emit('toggle-nueva-entrega')
  }
}

defineExpose({
  focusNuevaEntrega: () => botonNuevaEntregaRef.value?.focus(),
})

const items = computed(() => {
  if (esDespachador.value) {
    return [{ label: 'Panel', to: `/t/${slug.value}/panel` }]
  }

  return [
    { label: 'Panel', to: `/t/${slug.value}/panel` },
    { label: 'Clientes', to: `/t/${slug.value}/panel/clientes` },
    { label: 'Usuarios', to: `/t/${slug.value}/panel/usuarios` },
    { label: 'Despachadores', to: `/t/${slug.value}/panel/despachadores` },
    { label: 'Conductores', to: `/t/${slug.value}/panel/conductores` },
    { label: 'Vehículos', to: `/t/${slug.value}/panel/vehiculos` },
    { label: 'Asignaciones', to: `/t/${slug.value}/panel/asignaciones` },
  ]
})

async function onLogout() {
  await auth.logout()
  router.push({ name: 'tenant-login', params: { slug: slug.value } })
}

function onClickConfiguracion() {
  router.push({ name: 'tenant-configuracion', params: { slug: slug.value } })
}
</script>

<template>
  <div class="min-h-screen bg-black/[0.03]">
    <UiNavbar
      logo-text="inDriver"
      :items="items"
      :mostrar-configuracion="auth.usuario?.rol === 'AdminCliente'"
      @click-configuracion="onClickConfiguracion"
    >
      <template #actions>
        <button
          v-if="mostrarNuevaEntrega"
          ref="botonNuevaEntregaRef"
          type="button"
          :aria-expanded="nuevaEntregaAbierta"
          class="rounded-lg bg-accent px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-heading focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
          @click="emit('toggle-nueva-entrega')"
          @keydown="onNuevaEntregaKeydown"
        >
          Nueva Entrega
        </button>
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
