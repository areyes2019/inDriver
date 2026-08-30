<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiAddressAutocomplete from '@/components/ui/UiAddressAutocomplete.vue'
import UiVistaPreviaRuta from '@/components/ui/UiVistaPreviaRuta.vue'

interface Recurso {
  id_cliente?: number
  id_despachador?: number
  id_conductor?: number
  id_vehiculo?: number
  nombre?: string
  placa?: string
  marca?: string | null
  modelo?: string | null
}

const route = useRoute()
const router = useRouter()
const slug = route.params.slug as string
const pedidoId = route.params.id as string

const form = reactive({
  id_cliente: '',
  nombre_solicitante: '',
  telefono_solicitante: '',
  direccion_recogida: '',
  latitud_recogida: '',
  longitud_recogida: '',
  direccion_entrega: '',
  latitud_entrega: '',
  longitud_entrega: '',
  fecha_servicio: '',
  lo_antes_posible: true,
  hora_desde: '',
  hora_hasta: '',
  modalidad_pago: 'RECEPTOR_PAGA_ENVIO',
  importe_envio: '0',
  importe_cobro: '0',
  id_despachador: '',
  id_conductor: '',
  id_vehiculo: '',
})

function coordenada(lat: string, lng: string) {
  const latNum = parseFloat(lat)
  const lngNum = parseFloat(lng)
  return Number.isFinite(latNum) && Number.isFinite(lngNum) ? { lat: latNum, lng: lngNum } : null
}

const origenRuta = computed(() => coordenada(form.latitud_recogida, form.longitud_recogida))
const destinoRuta = computed(() => coordenada(form.latitud_entrega, form.longitud_entrega))

function onSeleccionaRecogida(p: { lat: number | null; lng: number | null }) {
  if (p.lat !== null) form.latitud_recogida = String(p.lat)
  if (p.lng !== null) form.longitud_recogida = String(p.lng)
}

function onSeleccionaEntrega(p: { lat: number | null; lng: number | null }) {
  if (p.lat !== null) form.latitud_entrega = String(p.lat)
  if (p.lng !== null) form.longitud_entrega = String(p.lng)
}

const clientes = ref<Recurso[]>([])
const despachadores = ref<Recurso[]>([])
const conductores = ref<Recurso[]>([])
const vehiculos = ref<Recurso[]>([])

const fieldErrors = reactive<Record<string, string>>({})
const error = ref('')
const success = ref('')
const loading = ref(false)
const loadingPedido = ref(true)

onMounted(async () => {
  try {
    const [recursos, pedido] = await Promise.all([
      http.get(`/t/${slug}/pedidos/recursos`),
      http.get(`/t/${slug}/pedidos/${pedidoId}`),
    ])

    clientes.value = recursos.data.clientes
    despachadores.value = recursos.data.despachadores
    conductores.value = recursos.data.conductores
    vehiculos.value = recursos.data.vehiculos

    const p = pedido.data.data ?? pedido.data

    form.id_cliente = p.id_cliente ?? ''
    form.nombre_solicitante = p.nombre_solicitante ?? ''
    form.telefono_solicitante = p.telefono_solicitante ?? ''
    form.direccion_recogida = p.direccion_recogida ?? ''
    form.latitud_recogida = p.latitud_recogida ?? ''
    form.longitud_recogida = p.longitud_recogida ?? ''
    form.direccion_entrega = p.direccion_entrega ?? ''
    form.latitud_entrega = p.latitud_entrega ?? ''
    form.longitud_entrega = p.longitud_entrega ?? ''
    form.fecha_servicio = p.fecha_servicio ?? ''
    form.lo_antes_posible = p.lo_antes_posible ?? true
    form.hora_desde = (p.hora_desde ?? '').slice(0, 5)
    form.hora_hasta = (p.hora_hasta ?? '').slice(0, 5)
    form.modalidad_pago = p.modalidad_pago ?? 'RECEPTOR_PAGA_ENVIO'
    form.importe_envio = String(p.importe_envio ?? '0')
    form.importe_cobro = String(p.importe_cobro ?? '0')
    form.id_despachador = p.id_despachador ?? ''
    form.id_conductor = p.id_conductor ?? ''
    form.id_vehiculo = p.id_vehiculo ?? ''
  } catch {
    error.value = 'No se pudo cargar el pedido.'
  } finally {
    loadingPedido.value = false
  }
})

async function onSubmit() {
  error.value = ''
  success.value = ''
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  loading.value = true

  try {
    await http.put(`/t/${slug}/pedidos/${pedidoId}`, {
      ...form,
      id_cliente: form.id_cliente || null,
    })
    success.value = 'Pedido actualizado correctamente.'
    setTimeout(() => router.push({ name: 'tenant-pedidos-lista', params: { slug } }), 1200)
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      for (const [field, messages] of Object.entries(errors)) {
        fieldErrors[field] = (messages as string[])[0] ?? ''
      }
      if (errors.estado) {
        error.value = (errors.estado as string[])[0] ?? ''
      }
    } else {
      error.value = 'No se pudo actualizar el pedido, intenta de nuevo.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <TenantLayout>
    <UiCard title="Editar pedido">
      <p v-if="loadingPedido" class="text-sm text-black/50">Cargando...</p>

      <form v-else class="max-w-2xl space-y-5" @submit.prevent="onSubmit">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">
            Cliente frecuente (opcional)
          </span>
          <select
            v-model="form.id_cliente"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          >
            <option value="">Sin cliente registrado</option>
            <option v-for="c in clientes" :key="c.id_cliente" :value="c.id_cliente">
              {{ c.nombre }}
            </option>
          </select>
        </label>

        <div class="grid grid-cols-2 gap-4">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Nombre del solicitante</span>
            <input
              v-model="form.nombre_solicitante"
              type="text"
              required
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
            <span v-if="fieldErrors.nombre_solicitante" class="mt-1 block text-sm text-red-600">
              {{ fieldErrors.nombre_solicitante }}
            </span>
          </label>

          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading"
              >Teléfono del solicitante</span
            >
            <input
              v-model="form.telefono_solicitante"
              type="tel"
              required
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
            <span v-if="fieldErrors.telefono_solicitante" class="mt-1 block text-sm text-red-600">
              {{ fieldErrors.telefono_solicitante }}
            </span>
          </label>
        </div>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Dirección de recogida</span>
          <UiAddressAutocomplete
            v-model="form.direccion_recogida"
            required
            @select="onSeleccionaRecogida"
          />
          <span v-if="fieldErrors.direccion_recogida" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.direccion_recogida }}
          </span>
        </label>

        <div class="grid grid-cols-2 gap-4">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Latitud de recogida</span>
            <input
              v-model="form.latitud_recogida"
              type="number"
              step="any"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Longitud de recogida</span>
            <input
              v-model="form.longitud_recogida"
              type="number"
              step="any"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
          </label>
        </div>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Dirección de entrega</span>
          <UiAddressAutocomplete
            v-model="form.direccion_entrega"
            required
            @select="onSeleccionaEntrega"
          />
          <span v-if="fieldErrors.direccion_entrega" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.direccion_entrega }}
          </span>
        </label>

        <div class="grid grid-cols-2 gap-4">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Latitud de entrega</span>
            <input
              v-model="form.latitud_entrega"
              type="number"
              step="any"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Longitud de entrega</span>
            <input
              v-model="form.longitud_entrega"
              type="number"
              step="any"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
          </label>
        </div>

        <UiVistaPreviaRuta :origen="origenRuta" :destino="destinoRuta" />

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Fecha de servicio</span>
          <input
            v-model="form.fecha_servicio"
            type="date"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
          <span v-if="fieldErrors.fecha_servicio" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.fecha_servicio }}
          </span>
        </label>

        <label class="flex items-center gap-2">
          <input v-model="form.lo_antes_posible" type="checkbox" class="rounded border-gray-300" />
          <span class="text-sm font-medium text-heading">Lo antes posible</span>
        </label>

        <div v-if="!form.lo_antes_posible" class="grid grid-cols-2 gap-4">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Hora desde</span>
            <input
              v-model="form.hora_desde"
              type="time"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
            <span v-if="fieldErrors.hora_desde" class="mt-1 block text-sm text-red-600">
              {{ fieldErrors.hora_desde }}
            </span>
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Hora hasta</span>
            <input
              v-model="form.hora_hasta"
              type="time"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
            <span v-if="fieldErrors.hora_hasta" class="mt-1 block text-sm text-red-600">
              {{ fieldErrors.hora_hasta }}
            </span>
          </label>
        </div>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">Modalidad de pago</span>
          <select
            v-model="form.modalidad_pago"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          >
            <option value="RECEPTOR_PAGA_ENVIO">Receptor paga envío</option>
            <option value="REMITENTE_PAGA_ENVIO">Remitente paga envío</option>
            <option value="RECEPTOR_PAGA_ENVIO_PRODUCTOS">Receptor paga envío y productos</option>
          </select>
          <span v-if="fieldErrors.modalidad_pago" class="mt-1 block text-sm text-red-600">
            {{ fieldErrors.modalidad_pago }}
          </span>
        </label>

        <div class="grid grid-cols-2 gap-4">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Importe de envío</span>
            <input
              v-model="form.importe_envio"
              type="number"
              step="0.01"
              min="0"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
            <span v-if="fieldErrors.importe_envio" class="mt-1 block text-sm text-red-600">
              {{ fieldErrors.importe_envio }}
            </span>
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Importe de cobro</span>
            <input
              v-model="form.importe_cobro"
              type="number"
              step="0.01"
              min="0"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
            <span v-if="fieldErrors.importe_cobro" class="mt-1 block text-sm text-red-600">
              {{ fieldErrors.importe_cobro }}
            </span>
          </label>
        </div>

        <div class="grid grid-cols-3 gap-4">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Despachador</span>
            <select
              v-model="form.id_despachador"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            >
              <option value="">Sin asignar</option>
              <option v-for="d in despachadores" :key="d.id_despachador" :value="d.id_despachador">
                {{ d.nombre }}
              </option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Conductor</span>
            <select
              v-model="form.id_conductor"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            >
              <option value="">Sin asignar</option>
              <option v-for="c in conductores" :key="c.id_conductor" :value="c.id_conductor">
                {{ c.nombre }}
              </option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Vehículo</span>
            <select
              v-model="form.id_vehiculo"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            >
              <option value="">Sin asignar</option>
              <option v-for="v in vehiculos" :key="v.id_vehiculo" :value="v.id_vehiculo">
                {{ v.placa }} {{ [v.marca, v.modelo].filter(Boolean).join(' ') }}
              </option>
            </select>
          </label>
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
