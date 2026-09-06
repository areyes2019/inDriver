<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/lib/http'
import UiBadge from '@/components/ui/UiBadge.vue'
import realtimeService from '@/services/realtime'

interface ConductorActivo {
  id_conductor: number
  nombre: string
  disponibilidad: 'DISPONIBLE' | 'OCUPADO' | 'DESCANSO' | 'FUERA_DE_SERVICIO'
  placa: string | null
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

const disponibilidadColor: Record<string, 'gray' | 'blue' | 'orange' | 'green' | 'red'> = {
  DISPONIBLE: 'blue',
  OCUPADO: 'orange',
  DESCANSO: 'gray',
  FUERA_DE_SERVICIO: 'gray',
}

const route = useRoute()
const slug = route.params.slug as string

const conductores = ref<ConductorActivo[]>([])
const cargando = ref(false)
const error = ref(false)
const toasts = ref<Toast[]>([])

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

function mostrarToast(texto: string) {
  const id = crypto.randomUUID()
  toasts.value.push({ id, texto })
  setTimeout(() => {
    toasts.value = toasts.value.filter((toast) => toast.id !== id)
  }, 4000)
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

onMounted(() => {
  cargarConductores()
  realtimeService
    .subscribe(slug)
    ?.bind('conductor.disponibilidad-cambiada', onDisponibilidadCambiada)
})

onUnmounted(() => {
  realtimeService
    .subscribe(slug)
    ?.unbind('conductor.disponibilidad-cambiada', onDisponibilidadCambiada)
})
</script>

<template>
  <aside
    class="fixed right-0 top-[4.25rem] z-30 flex h-[calc(100vh-4.25rem)] w-[30%] flex-col bg-white shadow-xl"
  >
    <header class="border-b border-default px-5 py-4">
      <h2 class="text-base font-semibold text-heading">Conductores activos</h2>
    </header>

    <div class="flex-1 overflow-y-auto p-4">
      <p v-if="cargando" class="text-sm text-body">Cargando...</p>
      <div v-else-if="error" class="flex flex-col items-start gap-2">
        <p class="text-sm text-body">No se pudo cargar la lista de conductores.</p>
        <button
          type="button"
          class="text-sm font-semibold text-heading underline"
          @click="cargarConductores"
        >
          Reintentar
        </button>
      </div>
      <p v-else-if="conductores.length === 0" class="text-sm text-body">
        No hay conductores activos
      </p>
      <ul v-else class="flex flex-col gap-3">
        <li
          v-for="conductor in conductores"
          :key="conductor.id_conductor"
          class="border border-default p-3"
        >
          <div class="flex items-center justify-between gap-2">
            <span class="truncate text-sm font-semibold text-heading">{{ conductor.nombre }}</span>
            <UiBadge
              :text="conductor.disponibilidad"
              :color="disponibilidadColor[conductor.disponibilidad] ?? 'gray'"
            />
          </div>
          <p class="truncate text-xs text-body/70">{{ conductor.placa ?? 'Sin vehículo' }}</p>
        </li>
      </ul>
    </div>

    <div class="pointer-events-none fixed bottom-4 right-[calc(30%+1rem)] z-40 flex flex-col gap-2">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        class="pointer-events-auto rounded bg-heading px-4 py-2 text-sm text-white shadow-lg"
      >
        {{ toast.texto }}
      </div>
    </div>
  </aside>
</template>
