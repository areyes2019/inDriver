<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import { useTenantAuthStore } from '@/stores/tenantAuth'

interface DespachadorActivo {
  id_despachador: number
  nombre: string
}

const route = useRoute()
const router = useRouter()
const slug = route.params.slug as string
const conductorId = route.params.id
const auth = useTenantAuthStore()
const usaDespachadores = computed(() => auth.usuario?.usar_despachadores === 'Sí')

const despachadoresActivos = ref<DespachadorActivo[]>([])
// Con 0 o 1 despachador activo no se pide el campo: sin ninguno no hay nada que elegir, y con uno
// solo se asigna automático en el backend (spec tenant/011).
const requiereElegirDespachador = computed(() => despachadoresActivos.value.length >= 2)

const form = reactive({
  numero_licencia: '',
  fecha_vencimiento_licencia: '',
  estado: 'ACTIVO',
  id_despachador: '',
  placa: '',
  marca: '',
})

const fieldErrors = reactive<Record<string, string>>({})
const error = ref('')
const success = ref('')
const loading = ref(false)
const loadingConductor = ref(true)

onMounted(async () => {
  if (usaDespachadores.value) {
    try {
      const { data } = await http.get(`/t/${slug}/despachadores/activos`)
      despachadoresActivos.value = data.data
    } catch {
      despachadoresActivos.value = []
    }
  }

  try {
    const { data } = await http.get(`/t/${slug}/conductores/${conductorId}`)
    const conductor = data.data ?? data

    form.numero_licencia = conductor.numero_licencia ?? ''
    form.fecha_vencimiento_licencia = conductor.fecha_vencimiento_licencia ?? ''
    form.estado = conductor.estado ?? 'ACTIVO'
    form.id_despachador = conductor.id_despachador ?? ''
    form.placa = conductor.vehiculo?.placa ?? ''
    form.marca = conductor.vehiculo?.marca ?? ''
  } catch {
    error.value = 'No se pudo cargar el conductor.'
  } finally {
    loadingConductor.value = false
  }
})

async function onSubmit() {
  error.value = ''
  success.value = ''
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  loading.value = true

  try {
    await http.put(`/t/${slug}/conductores/${conductorId}`, form)
    success.value = 'Conductor actualizado correctamente.'
    setTimeout(() => router.push({ name: 'tenant-conductores-lista', params: { slug } }), 1200)
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      for (const [field, messages] of Object.entries(errors)) {
        fieldErrors[field] = (messages as string[])[0] ?? ''
      }
    } else {
      error.value = 'No se pudo actualizar el conductor, intenta de nuevo.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <TenantLayout>
    <UiCard title="Editar conductor">
      <p v-if="loadingConductor" class="text-sm text-black/50">Cargando...</p>

      <form v-else class="max-w-lg space-y-5" @submit.prevent="onSubmit">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Número de licencia</span>
          <input
            v-model="form.numero_licencia"
            type="text"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.numero_licencia" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.numero_licencia }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">
            Fecha de vencimiento de licencia
          </span>
          <input
            v-model="form.fecha_vencimiento_licencia"
            type="date"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span
            v-if="fieldErrors.fecha_vencimiento_licencia"
            class="mt-1 block text-sm text-red-600"
          >
            {{ fieldErrors.fecha_vencimiento_licencia }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Estado</span>
          <select
            v-model="form.estado"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          >
            <option value="ACTIVO">ACTIVO</option>
            <option value="INACTIVO">INACTIVO</option>
            <option value="BLOQUEADO">BLOQUEADO</option>
          </select>
          <span v-if="fieldErrors.estado" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.estado }}
          </span>
        </label>

        <label v-if="requiereElegirDespachador" class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Despachador responsable</span>
          <select
            v-model="form.id_despachador"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          >
            <option value="">Sin asignar</option>
            <option
              v-for="despachador in despachadoresActivos"
              :key="despachador.id_despachador"
              :value="despachador.id_despachador"
            >
              {{ despachador.nombre }}
            </option>
          </select>
          <span v-if="fieldErrors.id_despachador" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.id_despachador }}
          </span>
        </label>

        <div class="border-t border-gray-100 pt-4">
          <h3 class="mb-3 text-sm font-semibold text-heading">Datos del vehículo</h3>

          <div class="space-y-5">
            <label class="block">
              <span class="mb-1 block text-sm font-medium text-heading">Placa</span>
              <input
                v-model="form.placa"
                type="text"
                required
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
              />
              <span v-if="fieldErrors.placa" class="mt-1 block text-sm text-red-600">
                {{ fieldErrors.placa }}
              </span>
            </label>

            <label class="block">
              <span class="mb-1 block text-sm font-medium text-heading">Marca</span>
              <input
                v-model="form.marca"
                type="text"
                required
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
              />
              <span v-if="fieldErrors.marca" class="mt-1 block text-sm text-red-600">
                {{ fieldErrors.marca }}
              </span>
            </label>
          </div>
        </div>

        <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
        <p v-if="success" class="text-sm text-green-600">{{ success }}</p>

        <div class="mt-2 border-t border-gray-100 pt-4">
          <button
            type="submit"
            :disabled="loading"
            class="w-full rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
          >
            Guardar cambios
          </button>
        </div>
      </form>
    </UiCard>
  </TenantLayout>
</template>
