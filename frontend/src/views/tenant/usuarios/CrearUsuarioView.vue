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
  nombre: '',
  apellido_paterno: '',
  apellido_materno: '',
  telefono: '',
  email: '',
  rol: 'Despachador',
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
    await http.post(`/t/${slug}/usuarios`, form)
    success.value =
      'Usuario creado correctamente. Se enviaron las credenciales de acceso al correo proporcionado.'
    setTimeout(() => router.push({ name: 'tenant-usuarios-lista', params: { slug } }), 1200)
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      for (const [field, messages] of Object.entries(errors)) {
        fieldErrors[field] = (messages as string[])[0] ?? ''
      }
    } else {
      error.value = 'No se pudo crear el usuario, intenta de nuevo.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <TenantLayout>
    <UiCard title="Nuevo usuario">
      <form class="max-w-lg space-y-5" @submit.prevent="onSubmit">
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
          <span class="mb-1 block text-sm font-medium text-heading">Apellido paterno</span>
          <input
            v-model="form.apellido_paterno"
            type="text"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.apellido_paterno" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.apellido_paterno }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Apellido materno</span>
          <input
            v-model="form.apellido_materno"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.apellido_materno" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.apellido_materno }}
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
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.email" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.email }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Rol</span>
          <select
            v-model="form.rol"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          >
            <option value="AdminCliente">AdminCliente</option>
            <option value="Despachador">Despachador</option>
            <option value="Conductor">Conductor</option>
          </select>
          <span v-if="fieldErrors.rol" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.rol }}
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
            Crear usuario
          </button>
        </div>
      </form>
    </UiCard>
  </TenantLayout>
</template>
