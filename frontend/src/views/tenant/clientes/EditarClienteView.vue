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
const clienteId = route.params.id

const form = reactive({
  nombre: '',
  telefono: '',
  email: '',
  referencia: '',
})

const fieldErrors = reactive<Record<string, string>>({})
const error = ref('')
const success = ref('')
const loading = ref(false)
const loadingCliente = ref(true)

onMounted(async () => {
  try {
    const { data } = await http.get(`/t/${slug}/clientes/${clienteId}`)
    const cliente = data.data ?? data
    form.nombre = cliente.nombre ?? ''
    form.telefono = cliente.telefono ?? ''
    form.email = cliente.email ?? ''
    form.referencia = cliente.referencia ?? ''
  } catch {
    error.value = 'No se pudo cargar el cliente.'
  } finally {
    loadingCliente.value = false
  }
})

async function onSubmit() {
  error.value = ''
  success.value = ''
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  loading.value = true

  try {
    await http.put(`/t/${slug}/clientes/${clienteId}`, form)
    success.value = 'Cliente actualizado correctamente.'
    setTimeout(() => router.push({ name: 'tenant-clientes-lista', params: { slug } }), 1200)
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      for (const [field, messages] of Object.entries(errors)) {
        fieldErrors[field] = (messages as string[])[0] ?? ''
      }
    } else {
      error.value = 'No se pudo actualizar el cliente, intenta de nuevo.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <TenantLayout>
    <UiCard title="Editar cliente">
      <p v-if="loadingCliente" class="text-sm text-black/50">Cargando...</p>

      <form v-else class="max-w-lg space-y-5" @submit.prevent="onSubmit">
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
          <span class="mb-1 block text-sm font-medium text-heading">Teléfono</span>
          <input
            v-model="form.telefono"
            type="tel"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.telefono" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.telefono }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Correo electrónico</span>
          <input
            v-model="form.email"
            type="email"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.email" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.email }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Referencia</span>
          <input
            v-model="form.referencia"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.referencia" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.referencia }}
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
