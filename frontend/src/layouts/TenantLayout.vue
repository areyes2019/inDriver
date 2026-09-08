<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import UiAmbienteSwitch from '@/components/ui/UiAmbienteSwitch.vue'
import UiNavbar from '@/components/ui/UiNavbar.vue'
import { useAmbienteStore } from '@/stores/ambiente'
import { useTenantAuthStore } from '@/stores/tenantAuth'

const props = withDefaults(
  defineProps<{
    nuevaEntregaAbierta?: boolean
    /**
     * spec tenant/023: solo el Panel lo activa. Saca al `<main>` del carril centrado de 1280px con
     * padding para que el mapa llegue a los bordes de la ventana; el resto de las pantallas del
     * tenant conserva el carril.
     */
    anchoCompleto?: boolean
  }>(),
  {
    nuevaEntregaAbierta: false,
    anchoCompleto: false,
  },
)

const emit = defineEmits<{
  'toggle-nueva-entrega': []
}>()

const route = useRoute()
const router = useRouter()
const auth = useTenantAuthStore()
const ambientes = useAmbienteStore()

const slug = computed(() => route.params.slug as string)
const enPanel = computed(() => route.name === 'tenant-panel')
const esDespachador = computed(() => auth.usuario?.rol === 'Despachador')
const esAdminCliente = computed(() => auth.usuario?.rol === 'AdminCliente')
const usaDespachadores = computed(() => auth.usuario?.usar_despachadores === 'Sí')

// spec tenant/025: el indicador de ambiente acompaña a toda pantalla del tenant, así que se carga
// en cuanto hay sesión y slug, sin que cada vista tenga que pedirlo.
watch(
  [slug, () => auth.usuario],
  ([slugActual, usuario]) => {
    if (slugActual && usuario && !ambientes.cargado) {
      ambientes.cargar(slugActual)
    }
  },
  { immediate: true },
)
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
    <!--
      spec tenant/025: en TEST, una banda a lo ancho de la ventana. El interruptor de la cabecera
      es discreto por diseño, y confundir la pantalla de pruebas con la de operación real es
      justamente lo que no puede pasar: los envíos de prueba descuentan saldo de verdad.
    -->
    <div
      v-if="ambientes.esTest"
      class="fixed inset-x-0 top-0 z-50 bg-amber-400 py-0.5 text-center text-xs font-semibold uppercase tracking-wider text-amber-950"
    >
      Modo prueba — los envíos que crees aquí son simulados
    </div>
    <UiNavbar logo-text="inDriver" :items="items" @click-configuracion="onClickConfiguracion">
      <template #actions>
        <UiAmbienteSwitch :slug="slug" :editable="esAdminCliente" />
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
    <main
      :class="
        anchoCompleto
          ? 'pt-[4.25rem]'
          : 'mx-auto max-w-screen-xl px-4 pb-4 pt-[5.25rem] md:px-8 md:pb-8 md:pt-[6.25rem]'
      "
    >
      <slot />
    </main>
  </div>
</template>
