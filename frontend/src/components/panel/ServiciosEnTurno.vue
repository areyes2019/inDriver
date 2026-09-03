<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/lib/http'
import UiBadge from '@/components/ui/UiBadge.vue'

interface ViajeEnTurno {
  numero_pedido: string
  cliente_nombre: string | null
  direccion_entrega: string
  estado: 'PENDIENTE' | 'PUBLICADO' | 'TOMADO' | 'ARRIBADO' | 'EN_CAMINO' | 'ARRIBADO_A_ENTREGA'
  lo_antes_posible: boolean
  hora_desde: string | null
}

interface PedidoApiItem {
  numero_pedido: string
  cliente_nombre: string | null
  direccion_entrega: string
  estado: string
  lo_antes_posible: boolean
  hora_desde: string | null
}

const ESTADOS_EN_TURNO = new Set([
  'PENDIENTE',
  'PUBLICADO',
  'TOMADO',
  'ARRIBADO',
  'EN_CAMINO',
  'ARRIBADO_A_ENTREGA',
])

const estadoColor: Record<string, 'gray' | 'blue' | 'orange' | 'green' | 'red'> = {
  PENDIENTE: 'gray',
  PUBLICADO: 'blue',
  TOMADO: 'orange',
  ARRIBADO: 'orange',
  EN_CAMINO: 'blue',
  ARRIBADO_A_ENTREGA: 'blue',
}

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

onMounted(cargarViajes)
onUnmounted(() => controller?.abort())

defineExpose({ recargar: cargarViajes })
</script>

<template>
  <aside
    class="fixed left-0 top-[4.25rem] z-30 flex h-[calc(100vh-4.25rem)] w-[30%] flex-col bg-white shadow-xl"
  >
    <header class="border-b border-default px-5 py-4">
      <h2 class="text-base font-semibold text-heading">Viajes en turno</h2>
    </header>

    <div class="flex-1 overflow-y-auto p-4">
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
        <li v-for="viaje in viajes" :key="viaje.numero_pedido" class="border border-default p-3">
          <div class="flex items-center justify-between gap-2">
            <span class="text-sm font-semibold text-heading">{{ viaje.numero_pedido }}</span>
            <UiBadge :text="viaje.estado" :color="estadoColor[viaje.estado] ?? 'gray'" />
          </div>
          <p class="truncate text-sm text-body">
            {{ viaje.cliente_nombre ?? 'Solicitante ocasional' }}
          </p>
          <p class="truncate text-xs text-body/70">{{ viaje.direccion_entrega }}</p>
          <p class="text-xs text-body/70">
            {{ viaje.lo_antes_posible ? 'Lo antes posible' : viaje.hora_desde }}
          </p>
        </li>
      </ul>
    </div>
  </aside>
</template>
