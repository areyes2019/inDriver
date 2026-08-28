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
const usuarioId = route.params.id

const form = reactive({
  nombre: '',
  apellido_paterno: '',
  apellido_materno: '',
  telefono: '',
  email: '',
  rol: '',
  estado: '',
})

const fieldErrors = reactive<Record<string, string>>({})
const error = ref('')
const success = ref('')
const loading = ref(false)
const loadingUsuario = ref(true)

onMounted(async () => {
  try {
    const { data } = await http.get(`/t/${slug}/usuarios/${usuarioId}`)
    const usuario = data.data ?? data
    form.nombre = usuario.nombre ?? ''
    form.apellido_paterno = usuario.apellido_paterno ?? ''
    form.apellido_materno = usuario.apellido_materno ?? ''
    form.telefono = usuario.telefono ?? ''
    form.email = usuario.email ?? ''
    form.rol = usuario.rol ?? ''
    form.estado = usuario.estado ?? ''
  } catch {
    error.value = 'No se pudo cargar el usuario.'
  } finally {
    loadingUsuario.value = false
  }
})

async function onSubmit() {
  error.value = ''
  success.value = ''
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  loading.value = true

  try {
    await http.put(`/t/${slug}/usuarios/${usuarioId}`, form)
    success.value = 'Usuario actualizado correctamente.'
    setTimeout(() => router.push({ name: 'tenant-usuarios-lista', params: { slug } }), 1200)
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      for (const [field, messages] of Object.entries(errors)) {
        fieldErrors[field] = (messages as string[])[0] ?? ''
      }
    } else {
      error.value = 'No se pudo actualizar el usuario, intenta de nuevo.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <TenantLayout>
    <UiCard title="Editar usuario">
      <p v-if="loadingUsuario" class="text-sm text-black/50">Cargando...</p>

      <form v-else class="max-w-lg space-y-5" @submit.prevent="onSubmit">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Nombre</span>
          <input
            v-model="form.nombre"
            type="text"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.nombre" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.nombre }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Apellido paterno</span>
          <input
            v-model="form.apellido_paterno"
            type="text"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.apellido_paterno" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.apellido_paterno }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Apellido materno</span>
          <input
            v-model="form.apellido_materno"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.apellido_materno" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.apellido_materno }}
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
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          />
          <span v-if="fieldErrors.email" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.email }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Rol</span>
          <select
            v-model="form.rol"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          >
            <option value="AdminCliente">AdminCliente</option>
            <option value="Despachador">Despachador</option>
            <option value="Conductor">Conductor</option>
          </select>
          <span v-if="fieldErrors.rol" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.rol }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Estado</span>
          <select
            v-model="form.estado"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          >
            <option value="Activo">Activo</option>
            <option value="Suspendido">Suspendido</option>
            <option value="Inactivo">Inactivo</option>
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
            class="w-full rounded-lg bg-brand-blue px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
          >
            Guardar cambios
          </button>
        </div>
      </form>
    </UiCard>
  </TenantLayout>
</template>
