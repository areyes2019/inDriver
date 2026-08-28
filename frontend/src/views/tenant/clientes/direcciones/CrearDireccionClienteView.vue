<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'

const route = useRoute()
const router = useRouter()
const slug = route.params.slug as string
const clienteId = route.params.id as string

const form = reactive({
  alias: '',
  calle: '',
  numero: '',
  colonia: '',
  cp: '',
  ciudad: '',
  estado: '',
  referencia: '',
  latitud: '',
  longitud: '',
  instrucciones_entrega: '',
})

const fieldErrors = reactive<Record<string, string>>({})
const error = ref('')
const success = ref('')
const loading = ref(false)

async function onSubmit() {
  error.value = ''
  success.value = ''
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  loading.value = true

  try {
    await http.post(`/t/${slug}/clientes/${clienteId}/direcciones`, form)
    success.value = 'Dirección creada correctamente.'
    setTimeout(
      () => router.push({ name: 'tenant-direcciones-lista', params: { slug, id: clienteId } }),
      1200,
    )
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      for (const [field, messages] of Object.entries(errors)) {
        fieldErrors[field] = (messages as string[])[0] ?? ''
      }
    } else {
      error.value = 'No se pudo crear la dirección, intenta de nuevo.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <TenantLayout>
    <UiCard title="Nueva dirección">
      <form class="max-w-lg space-y-5" @submit.prevent="onSubmit">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Alias</span>
          <input
            v-model="form.alias"
            type="text"
            placeholder="Ej. Casa, Trabajo, Negocio..."
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.alias" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.alias }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Calle</span>
          <input
            v-model="form.calle"
            type="text"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.calle" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.calle }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Número</span>
          <input
            v-model="form.numero"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.numero" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.numero }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Colonia</span>
          <input
            v-model="form.colonia"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.colonia" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.colonia }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Código postal</span>
          <input
            v-model="form.cp"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.cp" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.cp }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Ciudad</span>
          <input
            v-model="form.ciudad"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.ciudad" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.ciudad }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Estado</span>
          <input
            v-model="form.estado"
            type="text"
            placeholder="Ej. CDMX"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.estado" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.estado }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Referencia</span>
          <input
            v-model="form.referencia"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.referencia" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.referencia }}
          </span>
        </label>

        <div class="grid grid-cols-2 gap-4">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-brand-dark">Latitud</span>
            <input
              v-model="form.latitud"
              type="number"
              step="any"
              min="-90"
              max="90"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
            />
            <span v-if="fieldErrors.latitud" class="mt-1 block text-sm text-red-600">
              {{ fieldErrors.latitud }}
            </span>
          </label>

          <label class="block">
            <span class="mb-1 block text-sm font-medium text-brand-dark">Longitud</span>
            <input
              v-model="form.longitud"
              type="number"
              step="any"
              min="-180"
              max="180"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
            />
            <span v-if="fieldErrors.longitud" class="mt-1 block text-sm text-red-600">
              {{ fieldErrors.longitud }}
            </span>
          </label>
        </div>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">
            Instrucciones de entrega
          </span>
          <textarea
            v-model="form.instrucciones_entrega"
            rows="3"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.instrucciones_entrega" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.instrucciones_entrega }}
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
            Crear dirección
          </button>
        </div>
      </form>
    </UiCard>
  </TenantLayout>
</template>
