<script setup lang="ts">
import { onBeforeUnmount, shallowReactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiAlert from '@/components/ui/UiAlert.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiConfirmDialog from '@/components/ui/UiConfirmDialog.vue'
import { useConductorPrueba, type EstadoDemo } from '@/composables/useConductorPrueba'

interface ConductorPrueba {
  id_conductor: number
  nombre: string
  placa: string | null
  disponibilidad: string
  estado_conexion: string | null
}

const route = useRoute()
const slug = route.params.slug as string

const conductores = ref<ConductorPrueba[]>([])
const cargando = ref(false)
const error = ref('')
const creando = ref(false)
const nombreNuevo = ref('')

// Tokens en memoria, no persistidos (el conductor virtual vive mientras dure esta pestaña): el
// backend solo entrega el token una vez, al crearlo, igual que un login real de panda_express.
const tokens: Record<number, string> = {}
const demos = shallowReactive<Record<number, ReturnType<typeof useConductorPrueba>>>({})
const conductorAEliminar = ref<ConductorPrueba | null>(null)
const eliminandoId = ref<number | null>(null)
const reconectandoId = ref<number | null>(null)

const ETIQUETAS_ESTADO: Record<EstadoDemo, string> = {
  inactivo: 'Sin iniciar',
  conectando: 'Conectando...',
  esperando_oferta: 'Esperando oferta...',
  aceptando: 'Aceptando...',
  en_recogida: 'Llegando a recogida...',
  en_camino: 'En camino a la entrega...',
  llegando_entrega: 'Llegando a la entrega...',
  entregado: 'Entregado ✓',
  detenido: 'Detenida',
  error: 'Error',
}

async function cargarConductores() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await http.get(`/t/${slug}/conductores-prueba`)
    conductores.value = data.data
  } catch {
    error.value = 'No se pudo cargar la lista de conductores de prueba.'
  } finally {
    cargando.value = false
  }
}

async function crearConductor() {
  creando.value = true
  error.value = ''
  try {
    const { data } = await http.post(`/t/${slug}/conductores-prueba`, {
      nombre: nombreNuevo.value || undefined,
    })
    conductores.value.unshift(data.conductor)
    tokens[data.conductor.id_conductor] = data.token
    nombreNuevo.value = ''
  } catch {
    error.value = 'No se pudo crear el conductor de prueba.'
  } finally {
    creando.value = false
  }
}

async function reconectar(conductor: ConductorPrueba) {
  reconectandoId.value = conductor.id_conductor
  error.value = ''
  try {
    const { data } = await http.post(
      `/t/${slug}/conductores-prueba/${conductor.id_conductor}/reconectar`,
    )
    tokens[conductor.id_conductor] = data.token
    delete demos[conductor.id_conductor]
  } catch {
    error.value = 'No se pudo reconectar el conductor de prueba.'
  } finally {
    reconectandoId.value = null
  }
}

function demoDe(conductor: ConductorPrueba) {
  const token = tokens[conductor.id_conductor]
  if (!token) return null
  if (!demos[conductor.id_conductor]) {
    demos[conductor.id_conductor] = useConductorPrueba(slug, token)
  }
  return demos[conductor.id_conductor]
}

function correrDemo(conductor: ConductorPrueba) {
  demoDe(conductor)?.correr()
}

function detenerDemo(conductor: ConductorPrueba) {
  demos[conductor.id_conductor]?.detener()
}

function solicitarEliminar(conductor: ConductorPrueba) {
  conductorAEliminar.value = conductor
}

async function confirmarEliminar() {
  const conductor = conductorAEliminar.value
  if (!conductor) return
  conductorAEliminar.value = null

  eliminandoId.value = conductor.id_conductor
  try {
    await http.delete(`/t/${slug}/conductores-prueba/${conductor.id_conductor}`)
    conductores.value = conductores.value.filter((c) => c.id_conductor !== conductor.id_conductor)
    delete tokens[conductor.id_conductor]
    delete demos[conductor.id_conductor]
  } catch {
    error.value = 'No se pudo eliminar el conductor de prueba.'
  } finally {
    eliminandoId.value = null
  }
}

cargarConductores()

onBeforeUnmount(() => {
  Object.values(demos).forEach((demo) => demo.detener())
})
</script>

<template>
  <TenantLayout>
    <UiCard title="Modo prueba">
      <UiAlert variant="info" class="mb-5">
        Crea un conductor virtual, dale "Correr demo completa" y luego crea un envío normal desde
        "Nueva Entrega" en el Panel. El conductor virtual se conectará, aceptará la primera oferta
        que le llegue, recorrerá la ruta real de recogida a entrega (verás la moto moverse en el
        mapa del Panel) y marcará la entrega — sin usar un teléfono ni salir a la calle.
      </UiAlert>

      <form class="mb-6 flex flex-wrap items-end gap-3" @submit.prevent="crearConductor">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-heading">
            Nombre del conductor de prueba (opcional)
          </span>
          <input
            v-model="nombreNuevo"
            type="text"
            placeholder="Bot de pruebas"
            class="w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
          />
        </label>
        <button
          type="submit"
          :disabled="creando"
          class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60"
        >
          Crear conductor de prueba
        </button>
      </form>

      <p v-if="error" role="alert" class="mb-4 text-sm text-red-600">{{ error }}</p>
      <p v-if="cargando" class="text-sm text-black/50">Cargando...</p>
      <p v-else-if="conductores.length === 0" class="text-sm text-black/50">
        Aún no hay conductores de prueba.
      </p>

      <ul v-else class="space-y-4">
        <li
          v-for="conductor in conductores"
          :key="conductor.id_conductor"
          class="rounded-lg border border-gray-200 p-4"
        >
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p class="font-semibold text-heading">{{ conductor.nombre }}</p>
              <p class="text-xs text-body/70">{{ conductor.placa }}</p>
            </div>

            <div class="flex items-center gap-2">
              <UiBadge
                v-if="demos[conductor.id_conductor]"
                :text="ETIQUETAS_ESTADO[demos[conductor.id_conductor]!.estado.value]"
                :color="demos[conductor.id_conductor]!.estado.value === 'error' ? 'red' : 'blue'"
              />

              <button
                v-if="!tokens[conductor.id_conductor]"
                type="button"
                :disabled="reconectandoId === conductor.id_conductor"
                title="El token de este conductor se perdió al recargar la página."
                class="rounded-lg border border-accent px-3 py-1.5 text-sm font-semibold text-accent transition-colors hover:bg-accent hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
                @click="reconectar(conductor)"
              >
                Reconectar
              </button>
              <button
                v-else-if="
                  !demos[conductor.id_conductor] ||
                  ['inactivo', 'entregado', 'detenido', 'error'].includes(
                    demos[conductor.id_conductor]!.estado.value,
                  )
                "
                type="button"
                class="rounded-lg bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-heading"
                @click="correrDemo(conductor)"
              >
                Correr demo completa
              </button>
              <button
                v-else
                type="button"
                class="rounded-lg border border-red-600 px-3 py-1.5 text-sm font-semibold text-red-600 transition-colors hover:bg-red-600 hover:text-white"
                @click="detenerDemo(conductor)"
              >
                Detener
              </button>

              <button
                type="button"
                :disabled="eliminandoId === conductor.id_conductor"
                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-semibold text-heading transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                @click="solicitarEliminar(conductor)"
              >
                Eliminar
              </button>
            </div>
          </div>

          <ul
            v-if="demos[conductor.id_conductor]?.log.value.length"
            class="mt-3 space-y-1 border-t border-gray-100 pt-3 text-xs text-body"
          >
            <li v-for="(linea, indice) in demos[conductor.id_conductor]!.log.value" :key="indice">
              {{ linea }}
            </li>
          </ul>
        </li>
      </ul>
    </UiCard>

    <UiConfirmDialog
      :open="conductorAEliminar !== null"
      title="Eliminar conductor de prueba"
      :message="
        conductorAEliminar
          ? `¿Seguro que quieres eliminar a ${conductorAEliminar.nombre}? Esto no afecta conductores reales.`
          : ''
      "
      confirm-label="Eliminar"
      @confirm="confirmarEliminar"
      @cancel="conductorAEliminar = null"
    />
  </TenantLayout>
</template>
