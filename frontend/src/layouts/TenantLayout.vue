<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import UiAmbienteSwitch from '@/components/ui/UiAmbienteSwitch.vue'
import UiSidebar, { type ItemSidebar } from '@/components/ui/UiSidebar.vue'
import http from '@/lib/http'
import realtimeService from '@/services/realtime'
import { useAmbienteStore } from '@/stores/ambiente'
import { useTenantAuthStore } from '@/stores/tenantAuth'

const props = withDefaults(
  defineProps<{
    nuevaEntregaAbierta?: boolean
    /**
     * spec tenant/023 y tenant/032: solo el Panel lo activa. Saca al `<main>` del carril centrado de
     * 1280px y de todo relleno, para que el mapa llegue a los cuatro bordes de la ventana, también
     * por detrás del menú lateral; el resto de las pantallas del tenant conserva el carril.
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

const sidebarRef = ref<InstanceType<typeof UiSidebar>>()

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
    nextTick(() => sidebarRef.value?.focusAccion())
  }
})

function onNuevaEntregaKeydown(event: KeyboardEvent) {
  if ((event.key === 'ArrowRight' || event.key === 'ArrowDown') && !props.nuevaEntregaAbierta) {
    event.preventDefault()
    emit('toggle-nueva-entrega')
  }
}

defineExpose({
  focusNuevaEntrega: () => sidebarRef.value?.focusAccion(),
})

// Destinos del menú lateral (spec tenant/032), con las mismas reglas de rol que tenía la barra
// superior. Panel es exacto: todas las demás rutas del tenant empiezan con `/panel`.
const items = computed<ItemSidebar[]>(() => {
  const panel: ItemSidebar = {
    label: 'Panel',
    to: `/${slug.value}/panel`,
    icon: 'tabler:map',
    exacto: true,
  }
  // Icono provisional: la forma final de Conversaciones en el menú está pendiente (spec 032, §10).
  const conversaciones: ItemSidebar = {
    label: 'Conversaciones',
    to: `/${slug.value}/panel/conversaciones`,
    icon: 'tabler:messages',
    badge: esperando.value,
  }

  if (esDespachador.value) {
    return esOperativo.value ? [panel, conversaciones] : [conversaciones]
  }

  const lista: ItemSidebar[] = []

  if (esOperativo.value) {
    lista.push(panel)
  }

  lista.push(conversaciones)

  lista.push(
    { label: 'Clientes', to: `/${slug.value}/panel/clientes`, icon: 'tabler:user' },
    { label: 'Usuarios', to: `/${slug.value}/panel/usuarios`, icon: 'tabler:users' },
  )

  if (usaDespachadores.value) {
    lista.push({
      label: 'Despachadores',
      to: `/${slug.value}/panel/despachadores`,
      icon: 'tabler:headset',
    })
  }

  lista.push({
    label: 'Conductores',
    to: `/${slug.value}/panel/conductores`,
    icon: 'tabler:steering-wheel',
  })

  return lista
})

const accionNuevaEntrega = computed(() =>
  mostrarNuevaEntrega.value
    ? { label: 'Nueva Entrega', icon: 'tabler:plus', expandida: props.nuevaEntregaAbierta }
    : null,
)

const usuarioMenu = computed(() => {
  const usuario = auth.usuario
  if (!usuario) return null

  return {
    nombre: `${usuario.nombre} ${usuario.apellido_paterno}`,
    correo: usuario.email,
    iniciales: `${usuario.nombre.charAt(0)}${usuario.apellido_paterno.charAt(0)}`.toUpperCase(),
  }
})

// Medidas del riel (spec tenant/032, §5). Los paneles del Panel las leen para colocarse junto al
// riel y debajo de la banda de "Modo prueba".
const variablesRiel = computed(() => ({
  '--riel-ancho': '4rem',
  '--riel-ancho-abierto': '15rem',
  '--riel-margen': '0.75rem',
  '--riel-ocupado': 'calc(var(--riel-ancho) + 2 * var(--riel-margen))',
  '--alto-banda': ambientes.esTest ? '1.25rem' : '0px',
}))

function onCambiarPassword() {
  router.push({ name: 'tenant-cambiar-password', params: { slug: slug.value } })
}

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
  <div class="min-h-screen bg-black/[0.03]" :style="variablesRiel">
    <!--
      spec tenant/025: en TEST, una banda a lo ancho de la ventana, por encima de todo. El
      interruptor del menú es discreto por diseño, y confundir la pantalla de pruebas con la de
      operación real es justamente lo que no puede pasar: los envíos de prueba descuentan saldo de
      verdad. Mide `--alto-banda`, y el menú y los paneles empiezan debajo de ella (spec 032).
    -->
    <div
      v-if="ambientes.esTest"
      class="fixed inset-x-0 top-0 z-50 flex h-(--alto-banda) items-center justify-center bg-amber-400 text-xs font-semibold uppercase tracking-wider text-amber-950"
    >
      Modo prueba — los envíos que crees aquí son simulados
    </div>
    <!-- Menú lateral en lugar de la barra superior: tenant/032-menu-lateral.md -->
    <UiSidebar
      ref="sidebarRef"
      logo-text="inDriver"
      :items="items"
      :accion="accionNuevaEntrega"
      :usuario="usuarioMenu"
      @accion="emit('toggle-nueva-entrega')"
      @accion-teclado="onNuevaEntregaKeydown"
      @configuracion="onClickConfiguracion"
      @cambiar-password="onCambiarPassword"
      @cerrar-sesion="onLogout"
    >
      <template #pie="{ abierto }">
        <UiAmbienteSwitch :slug="slug" :editable="esAdminCliente" :compacto="!abierto" />
      </template>
    </UiSidebar>
    <!--
      Sin barra superior no hay franja que reservar arriba: solo la banda de TEST (`--alto-banda`)
      y, a la izquierda, el riel cerrado (`--riel-ocupado`). El Panel no lleva relleno: el mapa va
      de borde a borde, también por detrás del riel.
    -->
    <main v-if="anchoCompleto">
      <slot />
    </main>
    <main v-else class="pl-(--riel-ocupado) pt-(--alto-banda)">
      <div class="mx-auto max-w-screen-xl p-4 md:p-8">
        <slot />
      </div>
    </main>
  </div>
</template>
