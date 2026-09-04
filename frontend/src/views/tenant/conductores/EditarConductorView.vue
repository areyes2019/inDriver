<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import { useTenantAuthStore } from '@/stores/tenantAuth'

interface DespachadorActivo {
  id_despachador: number
  nombre: string
}

const route = useRoute()
const router = useRouter()
const slug = route.params.slug as string
const conductorId = route.params.id
const auth = useTenantAuthStore()
const usaDespachadores = computed(() => auth.usuario?.usar_despachadores === 'Sí')

const despachadoresActivos = ref<DespachadorActivo[]>([])
// Con 0 o 1 despachador activo no se pide el campo: sin ninguno no hay nada que elegir, y con uno
// solo se asigna automático en el backend (spec tenant/011).
const requiereElegirDespachador = computed(() => despachadoresActivos.value.length >= 2)

const form = reactive({
  numero_licencia: '',
  tipo_licencia: '',
  fecha_vencimiento_licencia: '',
  telefono_emergencia: '',
  estado: 'ACTIVO',
  disponibilidad: 'FUERA_DE_SERVICIO',
  id_despachador: '',
  placa: '',
  marca: '',
  modelo: '',
  anio: '',
  color: '',
  tipo: '',
  numero_economico: '',
  estado_vehiculo: 'ACTIVO',
})

const fieldErrors = reactive<Record<string, string>>({})
const error = ref('')
const success = ref('')
const loading = ref(false)
const loadingConductor = ref(true)

onMounted(async () => {
  if (usaDespachadores.value) {
    try {
      const { data } = await http.get(`/t/${slug}/despachadores/activos`)
      despachadoresActivos.value = data.data
    } catch {
      despachadoresActivos.value = []
    }
  }

  try {
    const { data } = await http.get(`/t/${slug}/conductores/${conductorId}`)
    const conductor = data.data ?? data

    form.numero_licencia = conductor.numero_licencia ?? ''
    form.tipo_licencia = conductor.tipo_licencia ?? ''
    form.fecha_vencimiento_licencia = conductor.fecha_vencimiento_licencia ?? ''
    form.telefono_emergencia = conductor.telefono_emergencia ?? ''
    form.estado = conductor.estado ?? 'ACTIVO'
    form.disponibilidad = conductor.disponibilidad ?? 'FUERA_DE_SERVICIO'
    form.id_despachador = conductor.id_despachador ?? ''
    form.placa = conductor.vehiculo?.placa ?? ''
    form.marca = conductor.vehiculo?.marca ?? ''
    form.modelo = conductor.vehiculo?.modelo ?? ''
    form.anio = conductor.vehiculo?.anio ?? ''
    form.color = conductor.vehiculo?.color ?? ''
    form.tipo = conductor.vehiculo?.tipo ?? ''
    form.numero_economico = conductor.vehiculo?.numero_economico ?? ''
    form.estado_vehiculo = conductor.vehiculo?.estado ?? 'ACTIVO'
  } catch {
    error.value = 'No se pudo cargar el conductor.'
  } finally {
    loadingConductor.value = false
  }
})

async function onSubmit() {
  error.value = ''
  success.value = ''
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  loading.value = true

  try {
    await http.put(`/t/${slug}/conductores/${conductorId}`, form)
    success.value = 'Conductor actualizado correctamente.'
    setTimeout(() => router.push({ name: 'tenant-conductores-lista', params: { slug } }), 1200)
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      for (const [field, messages] of Object.entries(errors)) {
        fieldErrors[field] = (messages as string[])[0] ?? ''
      }
    } else {
      error.value = 'No se pudo actualizar el conductor, intenta de nuevo.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <TenantLayout>
    <UiCard title="Editar conductor">
      <p v-if="loadingConductor" class="text-sm text-black/50">Cargando...</p>

      <form v-else class="max-w-lg space-y-5" @submit.prevent="onSubmit">
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

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Estado</span>
          <select
            v-model="form.estado"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          >
            <option value="ACTIVO">ACTIVO</option>
            <option value="INACTIVO">INACTIVO</option>
            <option value="BLOQUEADO">BLOQUEADO</option>
          </select>
          <span v-if="fieldErrors.estado" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.estado }}
          </span>
        </label>

        <label v-if="requiereElegirDespachador" class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Despachador responsable</span>
          <select
            v-model="form.id_despachador"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          >
            <option value="">Sin asignar</option>
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
          <span class="mb-1 block text-sm font-medium text-heading">Disponibilidad</span>
          <select
            v-model="form.disponibilidad"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          >
            <option value="DISPONIBLE">DISPONIBLE</option>
            <option value="OCUPADO">OCUPADO</option>
            <option value="DESCANSO">DESCANSO</option>
            <option value="FUERA_DE_SERVICIO">FUERA_DE_SERVICIO</option>
          </select>
          <span v-if="fieldErrors.disponibilidad" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.disponibilidad }}
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

            <label class="block">
              <span class="mb-1 block text-sm font-medium text-heading">Estado del vehículo</span>
              <select
                v-model="form.estado_vehiculo"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
              >
                <option value="ACTIVO">ACTIVO</option>
                <option value="INACTIVO">INACTIVO</option>
                <option value="MANTENIMIENTO">MANTENIMIENTO</option>
              </select>
              <span v-if="fieldErrors.estado_vehiculo" class="mt-1 block text-sm text-red-600">
                {{ fieldErrors.estado_vehiculo }}
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
            Guardar cambios
          </button>
        </div>
      </form>
    </UiCard>
  </TenantLayout>
</template>
