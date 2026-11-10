<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import UiAmbienteSwitch from '@/components/ui/UiAmbienteSwitch.vue'
import UiSidebar, { type AccionSidebar, type ItemSidebar } from '@/components/ui/UiSidebar.vue'
import { type EstadoConexion } from '@/services/realtime'
import { useAmbienteStore } from '@/stores/ambiente'
import { useBandejaWhatsappStore } from '@/stores/bandejaWhatsapp'
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
    /**
     * spec tenant/032 (paso 2) y tenant/008: el icono de Viajes en turno del menú lateral. El Panel
     * es dueño del estado y de los datos; el layout no depende del store del Panel.
     */
    viajesEnTurnoAbierto?: boolean
    viajesPendientes?: number
    conexionPanel?: EstadoConexion
  }>(),
  {
    nuevaEntregaAbierta: false,
    anchoCompleto: false,
    viajesEnTurnoAbierto: false,
    viajesPendientes: 0,
    conexionPanel: 'vivo',
  },
)

const emit = defineEmits<{
  'toggle-nueva-entrega': []
  'toggle-viajes-en-turno': []
}>()

const ACCION_NUEVA_ENTREGA = 'nueva-entrega'
const ACCION_VIAJES_EN_TURNO = 'viajes-en-turno'

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
// Nueva Entrega y Viajes en turno se muestran con la misma condición: solo en el Panel y para el rol
// operativo (spec 032, RN-05).
const mostrarAccionesPanel = computed(() => enPanel.value && esOperativo.value)

const sidebarRef = ref<InstanceType<typeof UiSidebar>>()

// spec tenant/031, RN-55 y RN-60: cuántas conversaciones de WhatsApp esperan a una persona. Sin
// sonido ni push: solo el número en el menú y en la pestaña de WhatsApp del Panel, que se mueve con
// cada aviso del canal del Panel. Vive en un store para que los dos lean el mismo (spec 032).
const puedeVerBandeja = computed(() => esAdminCliente.value || esDespachador.value)
const bandeja = useBandejaWhatsappStore()
let usaBandeja = false

watch(
  [slug, puedeVerBandeja],
  ([slugActual, puede]) => {
    if (usaBandeja) {
      bandeja.detener()
      usaBandeja = false
    }
    if (!slugActual || !puede) return
    bandeja.iniciar(slugActual)
    usaBandeja = true
  },
  { immediate: true },
)

onUnmounted(() => {
  if (usaBandeja) bandeja.detener()
})

onMounted(() => {
  if (mostrarAccionesPanel.value) {
    nextTick(() => sidebarRef.value?.focusAccion(ACCION_NUEVA_ENTREGA))
  }
})

function onAccion(id: string) {
  if (id === ACCION_NUEVA_ENTREGA) emit('toggle-nueva-entrega')
  else if (id === ACCION_VIAJES_EN_TURNO) emit('toggle-viajes-en-turno')
}

// Solo el + tiene atajo con flechas (spec 006); Viajes en turno no (spec 032).
function onAccionTeclado(id: string, event: KeyboardEvent) {
  if (id !== ACCION_NUEVA_ENTREGA) return
  if ((event.key === 'ArrowRight' || event.key === 'ArrowDown') && !props.nuevaEntregaAbierta) {
    event.preventDefault()
    emit('toggle-nueva-entrega')
  }
}

const accionesPanel = computed<AccionSidebar[]>(() => {
  if (!mostrarAccionesPanel.value) return []

  return [
    {
      id: ACCION_NUEVA_ENTREGA,
      label: 'Nueva Entrega',
      icon: 'tabler:plus',
      expandida: props.nuevaEntregaAbierta,
      destacada: true,
    },
    {
      id: ACCION_VIAJES_EN_TURNO,
      label: 'Viajes en turno',
      icon: 'tabler:truck-delivery',
      expandida: props.viajesEnTurnoAbierto,
      badge: props.viajesPendientes,
      conexion: props.conexionPanel,
    },
  ]
})

defineExpose({
  focusNuevaEntrega: () => sidebarRef.value?.focusAccion(ACCION_NUEVA_ENTREGA),
  focusViajesEnTurno: () => sidebarRef.value?.focusAccion(ACCION_VIAJES_EN_TURNO),
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
  // La pantalla a pantalla completa de la bandeja. Dentro del Panel, el chat vive además en el panel
  // de la pestaña de WhatsApp (spec 032, D-12).
  const conversaciones: ItemSidebar = {
    label: 'Conversaciones',
    to: `/${slug.value}/panel/conversaciones`,
    icon: 'tabler:messages',
    badge: bandeja.esperando,
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
      :acciones="accionesPanel"
      :usuario="usuarioMenu"
      @accion="onAccion"
      @accion-teclado="onAccionTeclado"
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
