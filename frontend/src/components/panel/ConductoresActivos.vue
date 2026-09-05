<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/lib/http'
import UiBadge from '@/components/ui/UiBadge.vue'

interface ConductorActivo {
  id_conductor: number
  nombre: string
  disponibilidad: 'DISPONIBLE' | 'OCUPADO' | 'DESCANSO' | 'FUERA_DE_SERVICIO'
  placa: string | null
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

onMounted(cargarConductores)
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
  </aside>
</template>
