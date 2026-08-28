<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import AdminLayout from '@/layouts/AdminLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'

const route = useRoute()
const router = useRouter()
const tenantId = route.params.id

const form = reactive({
  nombre_comercial: '',
  razon_social: '',
  rfc: '',
  telefono: '',
  email: '',
})

const fieldErrors = reactive<Record<string, string>>({})
const error = ref('')
const success = ref('')
const loading = ref(false)
const loadingTenant = ref(true)

onMounted(async () => {
  try {
    const { data } = await http.get(`/admin/tenants/${tenantId}`)
    const tenant = data.data ?? data
    form.nombre_comercial = tenant.nombre_comercial ?? ''
    form.razon_social = tenant.razon_social ?? ''
    form.rfc = tenant.rfc ?? ''
    form.telefono = tenant.telefono ?? ''
    form.email = tenant.email ?? ''
  } catch {
    error.value = 'No se pudo cargar el tenant.'
  } finally {
    loadingTenant.value = false
  }
})

async function onSubmit() {
  error.value = ''
  success.value = ''
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  loading.value = true

  try {
    await http.put(`/admin/tenants/${tenantId}`, form)
    success.value = 'Tenant actualizado correctamente.'
    setTimeout(() => router.push({ name: 'admin-tenants-detalle', params: { id: tenantId } }), 1200)
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      for (const [field, messages] of Object.entries(errors)) {
        fieldErrors[field] = (messages as string[])[0] ?? ''
      }
    } else {
      error.value = 'No se pudo actualizar el tenant, intenta de nuevo.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AdminLayout>
    <UiCard title="Editar tenant">
      <p v-if="loadingTenant" class="text-sm text-black/50">Cargando...</p>

      <form v-else class="max-w-lg space-y-5" @submit.prevent="onSubmit">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Nombre comercial</span>
          <input
            v-model="form.nombre_comercial"
            type="text"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.nombre_comercial" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.nombre_comercial }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Razón social</span>
          <input
            v-model="form.razon_social"
            type="text"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.razon_social" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.razon_social }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">RFC</span>
          <input
            v-model="form.rfc"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.rfc" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.rfc }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Teléfono</span>
          <input
            v-model="form.telefono"
            type="tel"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.telefono" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.telefono }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Correo electrónico</span>
          <input
            v-model="form.email"
            type="email"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.email" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.email }}
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
  </AdminLayout>
</template>
