<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'

interface ConductorDisponible {
  id_conductor: number
  nombre: string
}

interface VehiculoDisponible {
  id_vehiculo: number
  placa: string
  marca: string | null
  modelo: string | null
}

const route = useRoute()
const router = useRouter()
const slug = route.params.slug as string

const conductoresDisponibles = ref<ConductorDisponible[]>([])
const vehiculosDisponibles = ref<VehiculoDisponible[]>([])
const loadingDisponibles = ref(true)

function hoy(): string {
  return new Date().toISOString().slice(0, 10)
}

const form = reactive({
  id_conductor: '',
  id_vehiculo: '',
  fecha_inicio: hoy(),
})

const fieldErrors = reactive<Record<string, string>>({})
const error = ref('')
const success = ref('')
const loading = ref(false)

onMounted(async () => {
  try {
    const { data } = await http.get(`/t/${slug}/conductor-vehiculo/disponibles`)
    conductoresDisponibles.value = data.conductores
    vehiculosDisponibles.value = data.vehiculos
  } catch {
    error.value = 'No se pudo cargar la lista de conductores y vehículos disponibles.'
  } finally {
    loadingDisponibles.value = false
  }
})

async function onSubmit() {
  error.value = ''
  success.value = ''
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  loading.value = true

  try {
    await http.post(`/t/${slug}/conductor-vehiculo`, form)
    success.value = 'Vehículo asignado correctamente.'
    setTimeout(() => router.push({ name: 'tenant-asignaciones-lista', params: { slug } }), 1200)
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      for (const [field, messages] of Object.entries(errors)) {
        fieldErrors[field] = (messages as string[])[0] ?? ''
      }
    } else {
      error.value = 'No se pudo asignar el vehículo, intenta de nuevo.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <TenantLayout>
    <UiCard title="Asignar vehículo">
      <p v-if="loadingDisponibles" class="text-sm text-black/50">Cargando...</p>

      <p
        v-else-if="conductoresDisponibles.length === 0 || vehiculosDisponibles.length === 0"
        class="text-sm text-black/60"
      >
        Hace falta al menos un conductor y un vehículo en estado Activo para poder asignar.
      </p>

      <form v-else class="max-w-lg space-y-5" @submit.prevent="onSubmit">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Conductor</span>
          <select
            v-model="form.id_conductor"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          >
            <option value="" disabled>Selecciona un conductor</option>
            <option
              v-for="conductor in conductoresDisponibles"
              :key="conductor.id_conductor"
              :value="conductor.id_conductor"
            >
              {{ conductor.nombre }}
            </option>
          </select>
          <span v-if="fieldErrors.id_conductor" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.id_conductor }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Vehículo</span>
          <select
            v-model="form.id_vehiculo"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          >
            <option value="" disabled>Selecciona un vehículo</option>
            <option
              v-for="vehiculo in vehiculosDisponibles"
              :key="vehiculo.id_vehiculo"
              :value="vehiculo.id_vehiculo"
            >
              {{ vehiculo.placa }} ({{
                [vehiculo.marca, vehiculo.modelo].filter(Boolean).join(' ') || 'sin marca/modelo'
              }})
            </option>
          </select>
          <span v-if="fieldErrors.id_vehiculo" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.id_vehiculo }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Fecha de inicio</span>
          <input
            v-model="form.fecha_inicio"
            type="date"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.fecha_inicio" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.fecha_inicio }}
          </span>
        </label>

        <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
        <p v-if="success" class="text-sm text-green-600">{{ success }}</p>

        <div class="mt-2 border-t border-gray-100 pt-4">
          <button
            type="submit"
            :disabled="loading"
            class="w-full rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
          >
            Asignar vehículo
          </button>
        </div>
      </form>
    </UiCard>
  </TenantLayout>
</template>
