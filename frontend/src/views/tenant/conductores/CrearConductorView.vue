<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import { useTenantAuthStore } from '@/stores/tenantAuth'

interface UsuarioDisponible {
  id_usuario: number
  nombre: string
  apellido_paterno: string
  email: string
}

interface DespachadorActivo {
  id_despachador: number
  nombre: string
}

const route = useRoute()
const router = useRouter()
const slug = route.params.slug as string
const auth = useTenantAuthStore()
const usaDespachadores = computed(() => auth.usuario?.usar_despachadores === 'Sí')

const usuariosDisponibles = ref<UsuarioDisponible[]>([])
const loadingUsuarios = ref(true)

const despachadoresActivos = ref<DespachadorActivo[]>([])
// Con 0 o 1 despachador activo no se pide el campo: sin ninguno no hay nada que elegir, y con uno
// solo se asigna automático en el backend (spec tenant/011).
const requiereElegirDespachador = computed(() => despachadoresActivos.value.length >= 2)

const form = reactive({
  id_usuario: '',
  numero_licencia: '',
  tipo_licencia: '',
  fecha_vencimiento_licencia: '',
  telefono_emergencia: '',
  id_despachador: '',
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

onMounted(async () => {
  try {
    const { data } = await http.get(`/t/${slug}/conductores/usuarios-disponibles`)
    usuariosDisponibles.value = data.data
  } catch {
    error.value = 'No se pudo cargar la lista de usuarios disponibles.'
  } finally {
    loadingUsuarios.value = false
  }

  if (usaDespachadores.value) {
    try {
      const { data } = await http.get(`/t/${slug}/despachadores/activos`)
      despachadoresActivos.value = data.data
    } catch {
      despachadoresActivos.value = []
    }
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
          <span class="mb-1 block text-sm font-medium text-heading">Usuario</span>
          <select
            v-model="form.id_usuario"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
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

        <label v-if="requiereElegirDespachador" class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Despachador responsable</span>
          <select
            v-model="form.id_despachador"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          >
            <option value="" disabled>Selecciona un despachador</option>
            <option
              v-for="despachador in despachadoresActivos"
              :key="despachador.id_despachador"
              :value="despachador.id_despachador"
            >
              {{ despachador.nombre }}
            </option>
          </select>
          <span v-if="fieldErrors.id_despachador" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.id_despachador }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Número de licencia</span>
          <input
            v-model="form.numero_licencia"
            type="text"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.numero_licencia" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.numero_licencia }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Tipo de licencia</span>
          <input
            v-model="form.tipo_licencia"
            type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.tipo_licencia" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.tipo_licencia }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">
            Fecha de vencimiento de licencia
          </span>
          <input
            v-model="form.fecha_vencimiento_licencia"
            type="date"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span
            v-if="fieldErrors.fecha_vencimiento_licencia"
            class="mt-1 block text-sm text-red-600"
          >
            {{ fieldErrors.fecha_vencimiento_licencia }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">
            Teléfono de emergencia
          </span>
          <input
            v-model="form.telefono_emergencia"
            type="tel"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.telefono_emergencia" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.telefono_emergencia }}
          </span>
        </label>

        <div class="border-t border-gray-100 pt-4">
          <h3 class="mb-3 text-sm font-semibold text-heading">Datos del vehículo</h3>

          <div class="space-y-5">
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
                required
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
                required
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
                required
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
                required
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
                required
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
                required
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
              />
              <span v-if="fieldErrors.numero_economico" class="mt-1 block text-sm text-red-600">
                {{ fieldErrors.numero_economico }}
              </span>
            </label>
          </div>
        </div>

        <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
        <p v-if="success" class="text-sm text-green-600">{{ success }}</p>

        <div class="mt-2 border-t border-gray-100 pt-4">
          <button
            type="submit"
            :disabled="loading"
            class="w-full rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
          >
            Crear conductor
          </button>
        </div>
      </form>
    </UiCard>
  </TenantLayout>
</template>
