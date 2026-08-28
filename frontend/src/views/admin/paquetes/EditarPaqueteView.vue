<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import AdminLayout from '@/layouts/AdminLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'

const route = useRoute()
const router = useRouter()
const paqueteId = route.params.id

const codigoPaquete = ref('')

const form = reactive({
  nombre: '',
  descripcion: '',
  cantidad_viajes: '',
  precio: '',
})

const fieldErrors = reactive<Record<string, string>>({})
const error = ref('')
const success = ref('')
const loading = ref(false)
const loadingPaquete = ref(true)

onMounted(async () => {
  try {
    const { data } = await http.get(`/admin/paquetes-viajes/${paqueteId}`)
    const paquete = data.data ?? data
    codigoPaquete.value = paquete.codigo_paquete ?? ''
    form.nombre = paquete.nombre ?? ''
    form.descripcion = paquete.descripcion ?? ''
    form.cantidad_viajes = paquete.cantidad_viajes ?? ''
    form.precio = paquete.precio ?? ''
  } catch {
    error.value = 'No se pudo cargar el paquete.'
  } finally {
    loadingPaquete.value = false
  }
})

async function onSubmit() {
  error.value = ''
  success.value = ''
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  loading.value = true

  try {
    await http.put(`/admin/paquetes-viajes/${paqueteId}`, {
      codigo_paquete: codigoPaquete.value,
      ...form,
    })
    success.value = 'Paquete actualizado correctamente.'
    setTimeout(() => router.push({ name: 'admin-paquetes-lista' }), 1200)
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      for (const [field, messages] of Object.entries(errors)) {
        fieldErrors[field] = (messages as string[])[0] ?? ''
      }
    } else {
      error.value = 'No se pudo actualizar el paquete, intenta de nuevo.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AdminLayout>
    <UiCard title="Editar paquete de viajes">
      <p v-if="loadingPaquete" class="text-sm text-black/50">Cargando...</p>

      <form v-else class="max-w-lg space-y-5" @submit.prevent="onSubmit">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Código de paquete</span>
          <input
            :value="codigoPaquete"
            type="text"
            disabled
            class="w-full rounded-lg border border-gray-200 bg-gray-100 px-3 py-2 text-sm text-black/60"
          />
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Nombre</span>
          <input
            v-model="form.nombre"
            type="text"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.nombre" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.nombre }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Descripción</span>
          <textarea
            v-model="form.descripcion"
            rows="3"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.descripcion" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.descripcion }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Cantidad de viajes</span>
          <input
            v-model="form.cantidad_viajes"
            type="number"
            min="1"
            step="1"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.cantidad_viajes" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.cantidad_viajes }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Precio</span>
          <input
            v-model="form.precio"
            type="number"
            min="0"
            step="0.01"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.precio" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.precio }}
          </span>
        </label>

        <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
        <p v-if="success" class="text-sm text-green-600">{{ success }}</p>

        <div class="pt-2">
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
  </AdminLayout>
</template>
