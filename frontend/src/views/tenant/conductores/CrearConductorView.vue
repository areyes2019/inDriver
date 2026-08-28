<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'

interface UsuarioDisponible {
  id_usuario: number
  nombre: string
  apellido_paterno: string
  email: string
}

const route = useRoute()
const router = useRouter()
const slug = route.params.slug as string

const usuariosDisponibles = ref<UsuarioDisponible[]>([])
const loadingUsuarios = ref(true)

const form = reactive({
  id_usuario: '',
  numero_licencia: '',
  tipo_licencia: '',
  fecha_vencimiento_licencia: '',
  telefono_emergencia: '',
})

const fieldErrors = reactive<Record<string, string>>({})
const error = ref('')
const success = ref('')
const loading = ref(false)

onMounted(async () => {
  try {
    const { data } = await http.get(`/t/${slug}/conductores/usuarios-disponibles`)
    usuariosDisponibles.value = data.data
  } catch {
    error.value = 'No se pudo cargar la lista de usuarios disponibles.'
  } finally {
    loadingUsuarios.value = false
  }
})

async function onSubmit() {
  error.value = ''
  success.value = ''
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  loading.value = true

  try {
    await http.post(`/t/${slug}/conductores`, form)
    success.value = 'Conductor creado correctamente.'
    setTimeout(() => router.push({ name: 'tenant-conductores-lista', params: { slug } }), 1200)
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      for (const [field, messages] of Object.entries(errors)) {
        fieldErrors[field] = (messages as string[])[0] ?? ''
      }
    } else {
      error.value = 'No se pudo crear el conductor, intenta de nuevo.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <TenantLayout>
    <UiCard title="Nuevo conductor">
      <p v-if="loadingUsuarios" class="text-sm text-black/50">Cargando...</p>

      <p v-else-if="usuariosDisponibles.length === 0" class="text-sm text-black/60">
        No hay usuarios disponibles para dar de alta como conductor. Primero crea un usuario con rol
        Conductor desde la pantalla de Usuarios.
      </p>

      <form v-else class="max-w-lg space-y-5" @submit.prevent="onSubmit">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-brand-dark">Usuario</span>
          <select
            v-model="form.id_usuario"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
          >
            <option value="" disabled>Selecciona un usuario</option>
            <option
              v-for="usuario in usuariosDisponibles"
              :key="usuario.id_usuario"
              :value="usuario.id_usuario"
            >
              {{ usuario.nombre }} {{ usuario.apellido_paterno }} ({{ usuario.email }})
            </option>
          </select>
          <span v-if="fieldErrors.id_usuario" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.id_usuario }}
          </span>
        </label>

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

        <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
        <p v-if="success" class="text-sm text-green-600">{{ success }}</p>

        <div class="mt-2 border-t border-gray-100 pt-4">
          <button
            type="submit"
            :disabled="loading"
            class="w-full rounded-lg bg-brand-blue px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
          >
            Crear conductor
          </button>
        </div>
      </form>
    </UiCard>
  </TenantLayout>
</template>
