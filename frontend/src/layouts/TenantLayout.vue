<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import UiAmbienteSwitch from '@/components/ui/UiAmbienteSwitch.vue'
import UiNavbar from '@/components/ui/UiNavbar.vue'
import http from '@/lib/http'
import realtimeService from '@/services/realtime'
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
// El rol "operativo" (el que crea pedidos y ve el Panel) lo decide el store (spec tenant/011),
// que es el mismo que consultan el router y el login.
const esOperativo = computed(() => auth.esOperativo)
const mostrarNuevaEntrega = computed(() => enPanel.value && esOperativo.value)

const botonNuevaEntregaRef = ref<HTMLButtonElement>()

// spec tenant/031, RN-55 y RN-60: cuántas conversaciones de WhatsApp esperan a una persona. Sin
// sonido ni push: solo el número en el menú, que se mueve con cada aviso del canal del Panel.
const puedeVerBandeja = computed(() => esAdminCliente.value || esDespachador.value)
const esperando = ref(0)

function onConversacionActualizada(datos: { esperando?: number }) {
  if (typeof datos.esperando === 'number') esperando.value = datos.esperando
}

async function cargarEsperando() {
  try {
    const { data } = await http.get(`/t/${slug.value}/whatsapp/conversaciones/esperando`)
    esperando.value = data.esperando ?? 0
  } catch {
    // El contador es una ayuda; si falla, el menú sigue funcionando.
  }
}

watch(
  [slug, puedeVerBandeja],
  ([slugActual, puede]) => {
    if (!slugActual || !puede) return
    cargarEsperando()
    realtimeService
      .subscribePanel(slugActual)
      ?.bind('whatsapp.conversacion', onConversacionActualizada)
  },
  { immediate: true },
)

onUnmounted(() => {
  realtimeService
    .subscribePanel(slug.value)
    ?.unbind('whatsapp.conversacion', onConversacionActualizada)
})

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
  const conversaciones = {
    label: 'Conversaciones',
    to: `/${slug.value}/panel/conversaciones`,
    badge: esperando.value,
  }

  if (esDespachador.value) {
    return esOperativo.value
      ? [{ label: 'Panel', to: `/${slug.value}/panel` }, conversaciones]
      : [conversaciones]
  }

  const lista: Array<{ label: string; to: string; badge?: number }> = []

  if (esOperativo.value) {
    lista.push({ label: 'Panel', to: `/${slug.value}/panel` })
  }

  lista.push(conversaciones)

  lista.push(
    { label: 'Clientes', to: `/${slug.value}/panel/clientes` },
    { label: 'Usuarios', to: `/${slug.value}/panel/usuarios` },
  )

  if (usaDespachadores.value) {
    lista.push({ label: 'Despachadores', to: `/${slug.value}/panel/despachadores` })
  }

  lista.push({ label: 'Conductores', to: `/${slug.value}/panel/conductores` })

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
