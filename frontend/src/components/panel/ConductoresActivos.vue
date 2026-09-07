<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { Icon } from '@iconify/vue'
import http from '@/lib/http'
import UiBadge from '@/components/ui/UiBadge.vue'
import realtimeService from '@/services/realtime'

interface PedidoAsignado {
  id_pedido: number
}

interface ConductorActivo {
  id_conductor: number
  nombre: string
  disponibilidad: 'DISPONIBLE' | 'OCUPADO' | 'DESCANSO' | 'FUERA_DE_SERVICIO'
  placa: string | null
  marca: string | null
  saldo_viajes: number
  pedido_asignado: PedidoAsignado | null
}

interface DisponibilidadCambiadaPayload {
  id_conductor: number
  disponibilidad: 'DISPONIBLE' | 'FUERA_DE_SERVICIO'
  event_id: string
}

interface Toast {
  id: string
  texto: string
}

const emit = defineEmits<{ 'colapso-terminado': [] }>()

const route = useRoute()
const slug = route.params.slug as string

const conductores = ref<ConductorActivo[]>([])
const cargando = ref(false)
const error = ref(false)
const toasts = ref<Toast[]>([])
// Estado local a propósito (spec tenant/023): ahora que el mapa ocupa la pantalla completa y ya no
// calcula márgenes según el ancho de este panel, nadie más necesita saber si está colapsado.
const colapsado = ref(false)

const enLinea = computed(() => conductores.value.length)

async function cargarConductores() {
  cargando.value = true
  error.value = false

  try {
    const { data } = await http.get(`/t/${slug}/conductores/activos`)
    conductores.value = data.data as ConductorActivo[]
  } catch {
    error.value = true
  } finally {
    cargando.value = false
  }
}

function inicial(nombre: string): string {
  return nombre.trim().charAt(0).toUpperCase()
}

/**
 * `vehiculos` solo conserva `placa` y `marca`: `modelo`, `anio` y `color` se eliminaron en la
 * migración del 4 de septiembre, así que no hay más que mostrar aquí.
 */
function textoVehiculo(conductor: ConductorActivo): string {
  if (!conductor.placa) return 'Sin vehículo'

  return conductor.marca ? `${conductor.marca} ${conductor.placa}` : conductor.placa
}

function textoSaldo(viajes: number): string {
  return `Saldo: ${viajes} ${viajes === 1 ? 'viaje' : 'viajes'}`
}

/**
 * El badge NO sale de `conductores.disponibilidad`: la app del repartidor solo escribe DISPONIBLE
 * al conectarse y FUERA_DE_SERVICIO al desconectarse (spec tenant/013), nunca OCUPADO — pintar ese
 * enum diría "Disponible" para alguien que va manejando con el paquete encima. La verdad de si está
 * ocupado es traer un pedido en curso (spec tenant/023).
 */
function badge(conductor: ConductorActivo): { texto: string; color: 'green' | 'orange' } {
  return conductor.pedido_asignado
    ? { texto: 'Ocupado', color: 'orange' }
    : { texto: 'Disponible', color: 'green' }
}

function mostrarToast(texto: string) {
  const id = crypto.randomUUID()
  toasts.value.push({ id, texto })
  setTimeout(() => {
    toasts.value = toasts.value.filter((toast) => toast.id !== id)
  }, 4000)
}

function sinAnimacion(): boolean {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

function alternarColapso() {
  colapsado.value = !colapsado.value

  // Con movimiento reducido la transición no ocurre y `transitionend` nunca llegaría, así que el
  // mapa se quedaría sin remedirse: se avisa en cuanto el DOM refleja el cambio.
  if (sinAnimacion()) {
    nextTick(() => emit('colapso-terminado'))
  }
}

function onTransitionEnd(event: TransitionEvent) {
  if (event.propertyName === 'transform' && event.target === event.currentTarget) {
    emit('colapso-terminado')
  }
}

/**
 * Se puso en línea o se desconectó (spec tenant/019): recarga la lista para que el Panel refleje
 * el cambio sin recargar la página, y avisa con un toast solo cuando se conecta.
 */
async function onDisponibilidadCambiada(payload: DisponibilidadCambiadaPayload) {
  await cargarConductores()

  if (payload.disponibilidad === 'DISPONIBLE') {
    const conductor = conductores.value.find((c) => c.id_conductor === payload.id_conductor)
    mostrarToast(conductor ? `${conductor.nombre} está en línea` : 'Un conductor está en línea')
  }
}

// spec tenant/023: con el badge calculado por pedido asignado y el saldo de viajes a la vista, la
// lista tiene cuatro fuentes de cambio además de las conexiones. Todas recargan en silencio; el
// toast sigue saliendo solo desde `conductor.disponibilidad-cambiada`.
const EVENTOS_RECARGA = [
  'pedido.tomado',
  'pedido.cancelado',
  'pedido.entregado',
  'saldo.acreditado',
] as const

onMounted(() => {
  cargarConductores()

  const channel = realtimeService.subscribe(slug)
  channel?.bind('conductor.disponibilidad-cambiada', onDisponibilidadCambiada)
  for (const evento of EVENTOS_RECARGA) {
    channel?.bind(evento, cargarConductores)
  }
})

onUnmounted(() => {
  const channel = realtimeService.subscribe(slug)
  channel?.unbind('conductor.disponibilidad-cambiada', onDisponibilidadCambiada)
  for (const evento of EVENTOS_RECARGA) {
    channel?.unbind(evento, cargarConductores)
  }
})
</script>

<template>
  <aside
    class="panel-deslizante fixed right-0 top-[4.25rem] z-30 flex h-[calc(100vh-4.25rem)] w-[20%] flex-col bg-white shadow-xl transition-transform duration-[400ms] ease-in-out"
    :class="colapsado ? 'translate-x-full' : 'translate-x-0'"
    @transitionend="onTransitionEnd"
  >
    <!--
      Vive fuera del borde izquierdo del panel (`-translate-x-full`), así que cuando el panel se va
      a la derecha este botón queda pegado al borde de la ventana y hace de pestaña de reapertura:
      un solo control para las dos direcciones, sin dejar al usuario sin salida.
    -->
    <button
      type="button"
      :aria-expanded="!colapsado"
      :aria-label="colapsado ? 'Mostrar panel de flotilla' : 'Ocultar panel de flotilla'"
      class="absolute left-0 top-3 flex h-9 w-9 -translate-x-full items-center justify-center rounded-l-lg border border-r-0 border-default bg-white text-body shadow-md transition-colors hover:bg-black/5 focus:outline-none focus:ring-2 focus:ring-accent"
      @click="alternarColapso"
    >
      <svg
        class="h-4 w-4 transition-transform duration-[400ms]"
        :class="colapsado ? 'rotate-180' : ''"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <path d="m9 18 6-6-6-6" />
      </svg>
    </button>

    <header class="flex items-center justify-between gap-2 border-b border-default px-4 py-3">
      <div class="flex min-w-0 items-center gap-2">
        <Icon icon="flat-color-icons:automotive" width="18" height="18" aria-hidden="true" />
        <h2 class="truncate text-xs font-semibold uppercase tracking-wide text-body">Flotilla</h2>
      </div>
      <span
        class="shrink-0 rounded-full border border-success-text/20 bg-success-bg px-2.5 py-1 text-xs font-semibold text-success-text"
      >
        {{ enLinea }} en línea
      </span>
    </header>

    <div class="flex-1 overflow-y-auto px-4">
      <p v-if="cargando" class="py-4 text-sm text-body">Cargando...</p>
      <div v-else-if="error" class="flex flex-col items-start gap-2 py-4">
        <p class="text-sm text-body">No se pudo cargar la lista de conductores.</p>
        <button
          type="button"
          class="text-sm font-semibold text-heading underline"
          @click="cargarConductores"
        >
          Reintentar
        </button>
      </div>
      <p v-else-if="conductores.length === 0" class="py-4 text-sm text-body">
        No hay conductores activos
      </p>
      <ul v-else class="flex flex-col">
        <li
          v-for="conductor in conductores"
          :key="conductor.id_conductor"
          class="flex items-start gap-3 border-b border-default py-3 last:border-b-0"
        >
          <span
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-sm font-semibold text-accent"
            aria-hidden="true"
          >
            {{ inicial(conductor.nombre) }}
          </span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-heading">{{ conductor.nombre }}</p>
            <p class="truncate text-sm font-semibold text-success-text">
              {{ textoSaldo(conductor.saldo_viajes) }}
            </p>
            <p class="truncate text-xs text-body/70">{{ textoVehiculo(conductor) }}</p>
          </div>
          <UiBadge :text="badge(conductor).texto" :color="badge(conductor).color" />
        </li>
      </ul>
    </div>
  </aside>

  <!--
    Hermano del `<aside>`, no hijo: un ancestro con `transform` pasa a ser el bloque contenedor de
    sus descendientes `position: fixed`, así que dentro del panel los toasts se irían con él al
    colapsarlo en vez de quedarse anclados a la ventana.
  -->
  <div
    class="pointer-events-none fixed bottom-4 z-40 flex flex-col gap-2 transition-[right] duration-[400ms] ease-in-out"
    :class="colapsado ? 'right-4' : 'right-[calc(20%+1rem)]'"
  >
    <div
      v-for="toast in toasts"
      :key="toast.id"
      class="pointer-events-auto rounded bg-heading px-4 py-2 text-sm text-white shadow-lg"
    >
      {{ toast.texto }}
    </div>
  </div>
</template>
