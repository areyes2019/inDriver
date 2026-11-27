<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import http from '@/lib/http'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'

/**
 * Conductores de un tenant vistos desde ADMIN_CENTRAL: lista con su saldo de viajes prepagados,
 * venta de viajes por monto (mismas reglas que el Panel: costo del viaje y tope del saldo del
 * tenant) y alta de un conductor nuevo con su usuario y su vehículo.
 */
const props = defineProps<{ idTenant: number }>()

interface Conductor {
  id_conductor: number
  nombre: string
  apellido_paterno: string
  email: string
  estado: string
  disponibilidad: string
  vehiculo: { placa: string; marca: string } | null
  saldo_viajes: number
}

interface Meta {
  costo_viaje_prepago: number
  saldo_viajes_tenant: number | null
  despachadores: { id_despachador: number; nombre: string }[]
}

const base = `/admin/tenants/${props.idTenant}/conductores`

const conductores = ref<Conductor[]>([])
const meta = ref<Meta | null>(null)
const cargando = ref(true)
const errorLista = ref('')

async function fetchConductores() {
  try {
    const { data } = await http.get(base)
    conductores.value = data.data
    meta.value = data.meta
    errorLista.value = ''
  } catch {
    errorLista.value = 'No se pudieron cargar los conductores.'
  } finally {
    cargando.value = false
  }
}

onMounted(fetchConductores)

function mensajeDeError(err: unknown, porDefecto: string): string {
  if (axios.isAxiosError(err)) {
    const errores = err.response?.data?.errors as Record<string, string[]> | undefined
    if (errores) return Object.values(errores).flat().join(' ')
    if (err.response?.data?.message) return err.response.data.message
  }
  return porDefecto
}

// --- Vender viajes ---

const montos = reactive<Record<number, string>>({})
const vendiendo = reactive<Record<number, boolean>>({})
const mensajeVenta = reactive<Record<number, { texto: string; error: boolean }>>({})

function viajesEstimados(idConductor: number): number | null {
  const monto = Number(montos[idConductor])
  const costo = meta.value?.costo_viaje_prepago ?? 0
  if (!monto || costo <= 0) return null
  return Math.floor(monto / costo)
}

async function onVender(conductor: Conductor) {
  const id = conductor.id_conductor
  vendiendo[id] = true
  delete mensajeVenta[id]

  try {
    const { data } = await http.post(`${base}/${id}/vender-viajes`, {
      monto_pagado: montos[id],
    })
    conductor.saldo_viajes = data.saldo_conductor
    montos[id] = ''
    mensajeVenta[id] = { texto: `Se acreditaron ${data.cantidad_viajes} viaje(s).`, error: false }
  } catch (err) {
    mensajeVenta[id] = { texto: mensajeDeError(err, 'No se pudo acreditar.'), error: true }
  } finally {
    vendiendo[id] = false
  }
}

// --- Alta de conductor ---

const formularioAbierto = ref(false)
const formVacio = {
  nombre: '',
  apellido_paterno: '',
  apellido_materno: '',
  telefono: '',
  email: '',
  numero_licencia: '',
  fecha_vencimiento_licencia: '',
  placa: '',
  marca: '',
  id_despachador: '',
}
const form = reactive({ ...formVacio })
const guardando = ref(false)
const errorAlta = ref('')
const exitoAlta = ref('')

async function onAgregar() {
  guardando.value = true
  errorAlta.value = ''
  exitoAlta.value = ''

  try {
    const { data } = await http.post(base, {
      ...form,
      apellido_materno: form.apellido_materno || null,
      telefono: form.telefono || null,
      fecha_vencimiento_licencia: form.fecha_vencimiento_licencia || null,
      id_despachador: form.id_despachador || null,
    })
    exitoAlta.value = `Conductor ${data.nombre} ${data.apellido_paterno} creado. Sus credenciales se enviaron a ${data.email}.`
    Object.assign(form, formVacio)
    formularioAbierto.value = false
    await fetchConductores()
  } catch (err) {
    errorAlta.value = mensajeDeError(err, 'No se pudo crear el conductor.')
  } finally {
    guardando.value = false
  }
}

const campoClase =
  'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none'
</script>

<template>
  <UiCard title="Conductores">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <p v-if="meta" class="text-sm text-black/60">
        Costo del viaje prepagado:
        <span class="font-semibold text-heading">
          {{ meta.costo_viaje_prepago > 0 ? `$${meta.costo_viaje_prepago}` : 'sin configurar' }}
        </span>
        · Saldo del tenant para vender:
        <span class="font-semibold text-heading">
          {{ meta.saldo_viajes_tenant ?? '—' }} viaje(s)
        </span>
      </p>
      <button
        type="button"
        class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-heading"
        @click="formularioAbierto = !formularioAbierto"
      >
        {{ formularioAbierto ? 'Cancelar' : 'Agregar conductor' }}
      </button>
    </div>

    <p v-if="exitoAlta" class="mb-4 text-sm text-green-600">{{ exitoAlta }}</p>

    <form
      v-if="formularioAbierto"
      class="mb-6 grid grid-cols-1 gap-3 rounded-xl border border-gray-100 p-4 sm:grid-cols-2 lg:grid-cols-3"
      @submit.prevent="onAgregar"
    >
      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Nombre</span>
        <input v-model="form.nombre" required :class="campoClase" />
      </label>
      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Apellido paterno</span>
        <input v-model="form.apellido_paterno" required :class="campoClase" />
      </label>
      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Apellido materno</span>
        <input v-model="form.apellido_materno" :class="campoClase" />
      </label>
      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Email</span>
        <input v-model="form.email" type="email" required :class="campoClase" />
      </label>
      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Teléfono</span>
        <input v-model="form.telefono" type="tel" :class="campoClase" />
      </label>
      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Número de licencia</span>
        <input v-model="form.numero_licencia" required :class="campoClase" />
      </label>
      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Vencimiento de licencia</span>
        <input v-model="form.fecha_vencimiento_licencia" type="date" :class="campoClase" />
      </label>
      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Placa</span>
        <input v-model="form.placa" required :class="campoClase" />
      </label>
      <label class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Marca</span>
        <input v-model="form.marca" required :class="campoClase" />
      </label>
      <label v-if="(meta?.despachadores.length ?? 0) > 1" class="block">
        <span class="mb-1 block text-sm font-medium text-heading">Despachador</span>
        <select v-model="form.id_despachador" required :class="campoClase">
          <option value="" disabled>Selecciona...</option>
          <option
            v-for="d in meta?.despachadores"
            :key="d.id_despachador"
            :value="d.id_despachador"
          >
            {{ d.nombre }}
          </option>
        </select>
      </label>
      <div class="flex items-end sm:col-span-2 lg:col-span-3">
        <p class="mr-auto text-xs text-black/50">
          Se crea su usuario con rol Conductor y le llegan sus credenciales por correo.
        </p>
        <button
          type="submit"
          :disabled="guardando"
          class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60"
        >
          Crear conductor
        </button>
      </div>
      <p v-if="errorAlta" role="alert" class="text-sm text-red-600 sm:col-span-2 lg:col-span-3">
        {{ errorAlta }}
      </p>
    </form>

    <p v-if="cargando" class="text-sm text-black/50">Cargando...</p>
    <p v-else-if="errorLista" role="alert" class="text-sm text-red-600">{{ errorLista }}</p>
    <p v-else-if="conductores.length === 0" class="text-sm text-black/50">
      Este tenant todavía no tiene conductores.
    </p>

    <div v-else class="overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead>
          <tr class="text-xs font-semibold tracking-wide text-black/50 uppercase">
            <th class="py-2 pr-4">Conductor</th>
            <th class="py-2 pr-4">Vehículo</th>
            <th class="py-2 pr-4">Estado</th>
            <th class="py-2 pr-4">Saldo</th>
            <th class="py-2">Acreditar viajes</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="conductor in conductores"
            :key="conductor.id_conductor"
            class="border-t border-gray-100 align-top"
          >
            <td class="py-3 pr-4">
              <p class="font-medium text-heading">
                {{ conductor.nombre }} {{ conductor.apellido_paterno }}
              </p>
              <p class="text-xs text-black/50">{{ conductor.email }}</p>
            </td>
            <td class="py-3 pr-4 text-heading">
              {{
                conductor.vehiculo
                  ? `${conductor.vehiculo.marca} · ${conductor.vehiculo.placa}`
                  : '—'
              }}
            </td>
            <td class="py-3 pr-4">
              <UiBadge
                :text="conductor.estado"
                :color="conductor.estado === 'ACTIVO' ? 'green' : 'orange'"
              />
            </td>
            <td
              class="py-3 pr-4 font-semibold"
              :class="conductor.saldo_viajes < 0 ? 'text-red-600' : 'text-heading'"
            >
              {{ conductor.saldo_viajes }} viaje(s)
            </td>
            <td class="py-3">
              <form class="flex items-center gap-2" @submit.prevent="onVender(conductor)">
                <input
                  v-model="montos[conductor.id_conductor]"
                  type="number"
                  min="0.01"
                  step="0.01"
                  required
                  placeholder="Monto $"
                  class="w-28 rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
                />
                <button
                  type="submit"
                  :disabled="vendiendo[conductor.id_conductor] || !montos[conductor.id_conductor]"
                  class="rounded-lg bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60"
                >
                  Acreditar
                </button>
              </form>
              <p
                v-if="viajesEstimados(conductor.id_conductor) !== null"
                class="mt-1 text-xs text-black/50"
              >
                = {{ viajesEstimados(conductor.id_conductor) }} viaje(s)
              </p>
              <p
                v-if="mensajeVenta[conductor.id_conductor]"
                class="mt-1 text-xs"
                :class="
                  mensajeVenta[conductor.id_conductor]?.error ? 'text-red-600' : 'text-green-600'
                "
              >
                {{ mensajeVenta[conductor.id_conductor]?.texto }}
              </p>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </UiCard>
</template>
