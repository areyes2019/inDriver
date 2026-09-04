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
const esAdminCliente = computed(() => auth.usuario?.rol === 'AdminCliente')
const usaDespachadores = computed(() => auth.usuario?.usar_despachadores === 'Sí')
// El rol "operativo" (el que crea pedidos y ve el Panel) depende de la configuración del tenant,
// no solo del rol: Despachador cuando el tenant usa despachadores, AdminCliente cuando no
// (spec tenant/011) — nunca ambos a la vez.
const esOperativo = computed(
  () =>
    (esDespachador.value && usaDespachadores.value) ||
    (esAdminCliente.value && !usaDespachadores.value),
)
const mostrarNuevaEntrega = computed(() => enPanel.value && esOperativo.value)

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
    return esOperativo.value ? [{ label: 'Panel', to: `/t/${slug.value}/panel` }] : []
  }

  const lista: Array<{ label: string; to: string }> = []

  if (esOperativo.value) {
    lista.push({ label: 'Panel', to: `/t/${slug.value}/panel` })
  }

  lista.push(
    { label: 'Clientes', to: `/t/${slug.value}/panel/clientes` },
    { label: 'Usuarios', to: `/t/${slug.value}/panel/usuarios` },
  )

  if (usaDespachadores.value) {
    lista.push({ label: 'Despachadores', to: `/t/${slug.value}/panel/despachadores` })
  }

  lista.push({ label: 'Conductores', to: `/t/${slug.value}/panel/conductores` })

  return lista
})

async function onLogout() {
  await auth.logout()
  router.push({ name: 'tenant-login', params: { slug: slug.value } })
}

function onClickConfiguracion() {
  const name =
    auth.usuario?.rol === 'AdminCliente' ? 'tenant-configuracion' : 'tenant-cambiar-password'
  router.push({ name, params: { slug: slug.value } })
}
</script>

<template>
  <div class="min-h-screen bg-black/[0.03]">
    <UiNavbar logo-text="inDriver" :items="items" @click-configuracion="onClickConfiguracion">
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
