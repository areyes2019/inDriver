<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { Icon } from '@iconify/vue'
import http from '@/lib/http'
import realtimeService from '@/services/realtime'

export interface ViajeEnTurno {
  id_pedido: number
  numero_pedido: string
  direccion_recogida: string
  direccion_entrega: string
  estado: 'PENDIENTE' | 'PUBLICADO' | 'TOMADO' | 'ARRIBADO' | 'EN_CAMINO' | 'ARRIBADO_A_ENTREGA'
  lo_antes_posible: boolean
  fecha_servicio: string | null
  hora_desde: string | null
  nombre_solicitante: string | null
  telefono_solicitante: string | null
  importe_envio: string | number | null
}

interface PedidoApiItem extends Omit<ViajeEnTurno, 'estado'> {
  estado: string
}

const ESTADOS_EN_TURNO = new Set([
  'PENDIENTE',
  'PUBLICADO',
  'TOMADO',
  'ARRIBADO',
  'EN_CAMINO',
  'ARRIBADO_A_ENTREGA',
])

withDefaults(defineProps<{ seleccionadoId?: number | null }>(), { seleccionadoId: null })

const emit = defineEmits<{ seleccionar: [viaje: ViajeEnTurno] }>()

const route = useRoute()
const slug = route.params.slug as string

const viajesRaw = ref<ViajeEnTurno[]>([])
const cargando = ref(false)
const error = ref(false)
let controller: AbortController | null = null

async function cargarViajes() {
  controller?.abort()
  const currentController = new AbortController()
  controller = currentController

  cargando.value = true
  error.value = false

  try {
    const acumulado: PedidoApiItem[] = []
    let page = 1
    let lastPage = 1

    do {
      const { data } = await http.get(`/t/${slug}/pedidos`, {
        params: { page },
        signal: currentController.signal,
      })
      acumulado.push(...(data.data as PedidoApiItem[]))
      lastPage = data.meta?.last_page ?? 1
      page += 1
    } while (page <= lastPage)

    viajesRaw.value = acumulado.filter((pedido) =>
      ESTADOS_EN_TURNO.has(pedido.estado),
    ) as ViajeEnTurno[]
  } catch {
    if (currentController.signal.aborted) return
    error.value = true
  } finally {
    if (!currentController.signal.aborted) cargando.value = false
  }
}

const viajes = computed(() => {
  const loAntesPosible = viajesRaw.value.filter((viaje) => viaje.lo_antes_posible)
  const conHora = viajesRaw.value
    .filter((viaje) => !viaje.lo_antes_posible)
    .slice()
    .sort((a, b) => (a.hora_desde ?? '').localeCompare(b.hora_desde ?? ''))
  return [...loAntesPosible, ...conHora]
})

// El backend manda `fecha_servicio` ('YYYY-MM-DD') y `hora_desde` por separado; la etiqueta
// "sáb 5 de sept 09:41 a.m." se compone aquí (spec tenant/008). Se acepta la abreviatura de mes que
// devuelve Intl en español ('sept'), sin mapear los doce meses a mano.
const formatoFecha = new Intl.DateTimeFormat('es-MX', {
  weekday: 'short',
  day: 'numeric',
  month: 'short',
})
const formatoHora = new Intl.DateTimeFormat('es-MX', {
  hour: '2-digit',
  minute: '2-digit',
  hour12: true,
})

function construirFecha(viaje: ViajeEnTurno): Date | null {
  if (!viaje.fecha_servicio) return null
  const hora = (viaje.hora_desde ?? '00:00').slice(0, 5)
  const fecha = new Date(`${viaje.fecha_servicio}T${hora}:00`)
  return Number.isNaN(fecha.getTime()) ? null : fecha
}

function etiquetaFecha(viaje: ViajeEnTurno): string {
  if (viaje.lo_antes_posible) return 'Lo antes posible'

  const fecha = construirFecha(viaje)
  if (fecha === null) return viaje.hora_desde ?? ''

  const partes = Object.fromEntries(
    formatoFecha.formatToParts(fecha).map((parte) => [parte.type, parte.value]),
  )
  return `${partes.weekday} ${partes.day} de ${partes.month} ${formatoHora.format(fecha)}`
}

// spec tenant/024: la lista deja de depender de que alguien recargue la página. Todo lo que saca
// un viaje de la lista o le cambia el estado visible llega por el canal del tenant (spec
// tenant/018) y dispara una recarga silenciosa — incluido `pedido.disponible`, que es como el
// despachador ve que su envío recién creado ya se le ofreció a la flotilla.
const EVENTOS_RECARGA = [
  'pedido.disponible',
  'pedido.tomado',
  // spec tenant/025: el viaje camina por sus estados desde el servidor (el simulador del modo
  // prueba, o el propio conductor desde su app); sin esto el Panel los mostraría congelados.
  'pedido.estado-cambiado',
  'pedido.cancelado',
  'pedido.entregado',
  'pedido.requiere-asignacion-manual',
] as const

onMounted(() => {
  cargarViajes()

  const channel = realtimeService.subscribe(slug)
  for (const evento of EVENTOS_RECARGA) {
    channel?.bind(evento, cargarViajes)
  }
})

onUnmounted(() => {
  controller?.abort()

  const channel = realtimeService.subscribe(slug)
  for (const evento of EVENTOS_RECARGA) {
    channel?.unbind(evento, cargarViajes)
  }
})

defineExpose({ recargar: cargarViajes })
</script>

<template>
  <aside
    class="fixed left-0 top-[4.25rem] z-30 flex h-[calc(100vh-4.25rem)] w-[20%] flex-col bg-white shadow-xl"
  >
    <header class="border-b border-default bg-white px-5 py-4">
      <h2 class="text-base font-semibold text-heading">Viajes en turno</h2>
    </header>

    <div class="flex-1 overflow-y-auto bg-slate-50 p-4">
      <p v-if="cargando" class="text-sm text-body">Cargando...</p>
      <div v-else-if="error" class="flex flex-col items-start gap-2">
        <p class="text-sm text-body">No se pudo cargar la lista de viajes.</p>
        <button
          type="button"
          class="text-sm font-semibold text-heading underline"
          @click="cargarViajes"
        >
          Reintentar
        </button>
      </div>
      <p v-else-if="viajes.length === 0" class="text-sm text-body">No hay viajes en turno</p>
      <ul v-else class="flex flex-col gap-3">
        <li v-for="viaje in viajes" :key="viaje.id_pedido">
          <button
            type="button"
            class="w-full rounded-lg border-l-4 border-accent bg-white p-3 text-left shadow-md transition-shadow hover:shadow-hover focus:outline-none focus:ring-2 focus:ring-accent"
            :class="viaje.id_pedido === seleccionadoId ? 'ring-2 ring-accent' : ''"
            @click="emit('seleccionar', viaje)"
          >
            <div class="flex items-center justify-between gap-2">
              <span class="flex min-w-0 items-center gap-1.5">
                <Icon
                  icon="flat-color-icons:calendar"
                  width="18"
                  height="18"
                  aria-hidden="true"
                  class="shrink-0"
                />
                <span class="truncate text-sm font-semibold text-heading">
                  #{{ viaje.numero_pedido }}
                </span>
              </span>
              <span class="shrink-0 text-xs font-medium text-accent">
                {{ etiquetaFecha(viaje) }}
              </span>
            </div>

            <div class="mt-2 flex min-w-0 items-center gap-2">
              <span class="h-2 w-2 shrink-0 rounded-full bg-blue-500" aria-hidden="true"></span>
              <p class="truncate text-sm text-heading">{{ viaje.direccion_recogida }}</p>
            </div>
            <div class="mt-1 flex min-w-0 items-center gap-2">
              <span class="h-2 w-2 shrink-0 rounded-full bg-red-500" aria-hidden="true"></span>
              <p class="truncate text-sm text-heading">{{ viaje.direccion_entrega }}</p>
            </div>
          </button>
        </li>
      </ul>
    </div>
  </aside>
</template>
