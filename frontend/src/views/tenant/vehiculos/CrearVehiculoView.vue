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

const form = reactive({
  placa: '',
  marca: '',
  modelo: '',
  anio: '',
  color: '',
  tipo: '',
  numero_economico: '',
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
    await http.post(`/t/${slug}/vehiculos`, form)
    success.value = 'Vehículo creado correctamente.'
    setTimeout(() => router.push({ name: 'tenant-vehiculos-lista', params: { slug } }), 1200)
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      for (const [field, messages] of Object.entries(errors)) {
        fieldErrors[field] = (messages as string[])[0] ?? ''
      }
    } else {
      error.value = 'No se pudo crear el vehículo, intenta de nuevo.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <TenantLayout>
    <UiCard title="Nuevo vehículo">
      <form class="max-w-lg space-y-5" @submit.prevent="onSubmit">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Placa</span>
          <input
            v-model="form.placa"
            type="text"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.placa" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.placa }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Marca</span>
          <input
            v-model="form.marca"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.marca" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.marca }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Modelo</span>
          <input
            v-model="form.modelo"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.modelo" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.modelo }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Año</span>
          <input
            v-model="form.anio"
            type="number"
            min="1900"
            max="2100"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.anio" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.anio }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Color</span>
          <input
            v-model="form.color"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.color" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.color }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Tipo</span>
          <input
            v-model="form.tipo"
            type="text"
            placeholder="Ej. Motocicleta, Automóvil..."
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.tipo" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.tipo }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Número económico</span>
          <input
            v-model="form.numero_economico"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.numero_economico" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.numero_economico }}
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
            Crear vehículo
          </button>
        </div>
      </form>
    </UiCard>
  </TenantLayout>
</template>
