<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'

const route = useRoute()
const router = useRouter()
const slug = route.params.slug as string
const conductorId = route.params.id

const form = reactive({
  numero_licencia: '',
  tipo_licencia: '',
  fecha_vencimiento_licencia: '',
  telefono_emergencia: '',
  estado: 'ACTIVO',
  disponibilidad: 'FUERA_DE_SERVICIO',
})

const fieldErrors = reactive<Record<string, string>>({})
const error = ref('')
const success = ref('')
const loading = ref(false)
const loadingConductor = ref(true)

onMounted(async () => {
  try {
    const { data } = await http.get(`/t/${slug}/conductores/${conductorId}`)
    const conductor = data.data ?? data

    form.numero_licencia = conductor.numero_licencia ?? ''
    form.tipo_licencia = conductor.tipo_licencia ?? ''
    form.fecha_vencimiento_licencia = conductor.fecha_vencimiento_licencia ?? ''
    form.telefono_emergencia = conductor.telefono_emergencia ?? ''
    form.estado = conductor.estado ?? 'ACTIVO'
    form.disponibilidad = conductor.disponibilidad ?? 'FUERA_DE_SERVICIO'
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
          <span class="mb-1 block text-sm font-medium text-brand-dark">Número de licencia</span>
          <input
            v-model="form.numero_licencia"
            type="text"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.numero_licencia" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.numero_licencia }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Tipo de licencia</span>
          <input
            v-model="form.tipo_licencia"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.tipo_licencia" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.tipo_licencia }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">
            Fecha de vencimiento de licencia
          </span>
          <input
            v-model="form.fecha_vencimiento_licencia"
            type="date"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span
            v-if="fieldErrors.fecha_vencimiento_licencia"
            class="mt-1 block text-sm text-red-600"
          >
            {{ fieldErrors.fecha_vencimiento_licencia }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">
            Teléfono de emergencia
          </span>
          <input
            v-model="form.telefono_emergencia"
            type="tel"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.telefono_emergencia" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.telefono_emergencia }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Estado</span>
          <select
            v-model="form.estado"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          >
            <option value="ACTIVO">ACTIVO</option>
            <option value="INACTIVO">INACTIVO</option>
            <option value="BLOQUEADO">BLOQUEADO</option>
          </select>
          <span v-if="fieldErrors.estado" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.estado }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Disponibilidad</span>
          <select
            v-model="form.disponibilidad"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          >
            <option value="DISPONIBLE">DISPONIBLE</option>
            <option value="OCUPADO">OCUPADO</option>
            <option value="DESCANSO">DESCANSO</option>
            <option value="FUERA_DE_SERVICIO">FUERA_DE_SERVICIO</option>
          </select>
          <span v-if="fieldErrors.disponibilidad" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.disponibilidad }}
          </span>
        </label>

        <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
        <p v-if="success" class="text-sm text-green-600">{{ success }}</p>

        <div class="mt-2 border-t border-gray-100 pt-4">
          <button
            type="submit"
            :disabled="loading"
            class="w-full rounded-lg bg-brand-blue px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
          >
            Guardar cambios
          </button>
        </div>
      </form>
    </UiCard>
  </TenantLayout>
</template>
