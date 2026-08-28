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
const vehiculoId = route.params.id

const form = reactive({
  placa: '',
  marca: '',
  modelo: '',
  anio: '',
  color: '',
  tipo: '',
  numero_economico: '',
  estado: 'ACTIVO',
})

const fieldErrors = reactive<Record<string, string>>({})
const error = ref('')
const success = ref('')
const loading = ref(false)
const loadingVehiculo = ref(true)

onMounted(async () => {
  try {
    const { data } = await http.get(`/t/${slug}/vehiculos/${vehiculoId}`)
    const vehiculo = data.data ?? data

    form.placa = vehiculo.placa ?? ''
    form.marca = vehiculo.marca ?? ''
    form.modelo = vehiculo.modelo ?? ''
    form.anio = vehiculo.anio ?? ''
    form.color = vehiculo.color ?? ''
    form.tipo = vehiculo.tipo ?? ''
    form.numero_economico = vehiculo.numero_economico ?? ''
    form.estado = vehiculo.estado ?? 'ACTIVO'
  } catch {
    error.value = 'No se pudo cargar el vehículo.'
  } finally {
    loadingVehiculo.value = false
  }
})

async function onSubmit() {
  error.value = ''
  success.value = ''
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  loading.value = true

  try {
    await http.put(`/t/${slug}/vehiculos/${vehiculoId}`, form)
    success.value = 'Vehículo actualizado correctamente.'
    setTimeout(() => router.push({ name: 'tenant-vehiculos-lista', params: { slug } }), 1200)
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      for (const [field, messages] of Object.entries(errors)) {
        fieldErrors[field] = (messages as string[])[0] ?? ''
      }
    } else {
      error.value = 'No se pudo actualizar el vehículo, intenta de nuevo.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <TenantLayout>
    <UiCard title="Editar vehículo">
      <p v-if="loadingVehiculo" class="text-sm text-black/50">Cargando...</p>

      <form v-else class="max-w-lg space-y-5" @submit.prevent="onSubmit">
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
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.marca" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.marca }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Modelo</span>
          <input
            v-model="form.modelo"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.modelo" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.modelo }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Año</span>
          <input
            v-model="form.anio"
            type="number"
            min="1900"
            max="2100"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.anio" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.anio }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Color</span>
          <input
            v-model="form.color"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.color" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.color }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Tipo</span>
          <input
            v-model="form.tipo"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.tipo" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.tipo }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Número económico</span>
          <input
            v-model="form.numero_economico"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.numero_economico" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.numero_economico }}
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
            <option value="MANTENIMIENTO">MANTENIMIENTO</option>
          </select>
          <span v-if="fieldErrors.estado" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.estado }}
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
            Guardar cambios
          </button>
        </div>
      </form>
    </UiCard>
  </TenantLayout>
</template>
