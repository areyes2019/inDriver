<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http from '@/lib/http'
import realtimeService from '@/services/realtime'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiStat from '@/components/ui/UiStat.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import LiquidarComisionModal, {
  type ConductorLiquidable,
  type LiquidacionRegistrada,
  type ResumenComisiones,
} from '@/components/comisiones/LiquidarComisionModal.vue'

/**
 * Comisiones de conductores (spec tenant/030): resumen del tenant, detalle por conductor y
 * liquidaciones. Todos los números vienen calculados del backend; aquí solo se pintan.
 */

interface ResumenTenant {
  viajes: number
  importe_envios: number
  generado: number
  pagado: number
  pendiente: number
  modalidad_conductores: 'Prepago' | 'Comision'
  comision_porcentaje: number
}

interface ConductorOpcion {
  id_conductor: number
  nombre: string
  apellido_paterno: string
}

interface ViajeComision {
  id_comision: number
  id_pedido: number
  numero_pedido: string | null
  fecha_entrega: string | null
  importe_envio: number
  porcentaje_aplicado: number
  importe_comision: number
  estado: 'PENDIENTE' | 'PAGADA'
  id_liquidacion: number | null
  created_at: string
}

interface DetalleConductor {
  conductor: ConductorLiquidable
  resumen: ResumenComisiones
  viajes: ViajeComision[]
  liquidaciones: LiquidacionRegistrada[]
}

const route = useRoute()
const router = useRouter()
const slug = computed(() => route.params.slug as string)

const formatoImporte = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' })
const dinero = (valor: number) => formatoImporte.format(valor)
const fecha = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '—')

// --- Resumen del tenant ---

const resumen = ref<ResumenTenant | null>(null)
const errorResumen = ref('')

async function fetchResumen() {
  errorResumen.value = ''
  try {
    const { data } = await http.get(`/t/${slug.value}/comisiones/resumen`)
    resumen.value = data
  } catch {
    errorResumen.value = 'No se pudo cargar el resumen de comisiones.'
  }
}

// --- Selector de conductor ---

const conductores = ref<ConductorOpcion[]>([])
const conductorSeleccionado = ref<number | null>(null)

/**
 * `GET /conductores` pagina a 15; para el selector se recorren todas las páginas. Es la misma
 * lista que ya alimenta `ListaConductoresView`, no un endpoint nuevo.
 */
async function fetchConductores() {
  const acumulado: ConductorOpcion[] = []
  let page = 1
  let lastPage = 1

  try {
    do {
      const { data } = await http.get(`/t/${slug.value}/conductores`, { params: { page } })
      acumulado.push(...data.data)
      lastPage = data.meta?.last_page ?? 1
      page += 1
    } while (page <= lastPage)
  } catch {
    // Con lo que se haya alcanzado a cargar es suficiente para elegir.
  }

  conductores.value = acumulado
}

// --- Detalle del conductor ---

const detalle = ref<DetalleConductor | null>(null)
const cargandoDetalle = ref(false)
const errorDetalle = ref('')

async function fetchDetalle() {
  const id = conductorSeleccionado.value
  if (id === null) {
    detalle.value = null
    return
  }

  cargandoDetalle.value = true
  errorDetalle.value = ''

  try {
    const { data } = await http.get(`/t/${slug.value}/conductores/${id}/comisiones`)
    // Si mientras cargaba se eligió otro conductor, esta respuesta ya no es la que se ve.
    if (conductorSeleccionado.value === id) detalle.value = data
  } catch {
    errorDetalle.value = 'No se pudo cargar el detalle de comisiones del conductor.'
  } finally {
    cargandoDetalle.value = false
  }
}

watch(conductorSeleccionado, (id) => {
  router.replace({ query: id === null ? {} : { conductor: String(id) } })
  fetchDetalle()
})

// --- Liquidación ---

const liquidando = ref(false)
const mensajeLiquidacion = ref('')

const puedeLiquidar = computed(() => detalle.value !== null && detalle.value.resumen.pendiente > 0)

function onLiquidado(payload: { liquidacion: LiquidacionRegistrada; resumen: ResumenComisiones }) {
  mensajeLiquidacion.value = `Se registró un pago de ${dinero(payload.liquidacion.monto)} a ${detalle.value?.conductor.nombre ?? 'el conductor'}.`
  fetchDetalle()
  fetchResumen()
}

// --- Tiempo real: una entrega nueva puede haber generado comisión (spec tenant/030) ---

function onPedidoEntregado() {
  fetchResumen()
  if (conductorSeleccionado.value !== null) fetchDetalle()
}

onMounted(async () => {
  const preseleccion = Number(route.query.conductor)
  if (Number.isInteger(preseleccion) && preseleccion > 0) conductorSeleccionado.value = preseleccion

  await Promise.all([fetchResumen(), fetchConductores()])
  if (conductorSeleccionado.value !== null) fetchDetalle()

  realtimeService.subscribe(slug.value)?.bind('pedido.entregado', onPedidoEntregado)
})

onUnmounted(() => {
  realtimeService.subscribe(slug.value)?.unbind('pedido.entregado', onPedidoEntregado)
})
</script>

<template>
  <TenantLayout>
    <div class="space-y-6">
      <UiCard
        title="Comisiones"
        subtitle="Lo que el negocio ha generado, pagado y debe a sus conductores"
      >
        <p v-if="errorResumen" role="alert" class="mb-4 text-sm text-red-600">{{ errorResumen }}</p>

        <p
          v-if="resumen && resumen.modalidad_conductores === 'Prepago'"
          class="mb-4 rounded-lg bg-accent-soft px-4 py-3 text-sm text-heading"
        >
          El negocio está en modalidad <strong>Prepago</strong>. Aquí solo se ven las comisiones
          generadas mientras estuvo en Comisión; los envíos nuevos no generan comisión.
        </p>

        <div v-if="resumen" class="flex flex-wrap gap-4">
          <UiStat
            icon="flat-color-icons:shipped"
            label="Viajes realizados"
            :value="resumen.viajes"
          />
          <UiStat
            icon="flat-color-icons:package"
            label="Importe de envíos"
            :value="dinero(resumen.importe_envios)"
          />
          <UiStat
            icon="flat-color-icons:positive-dynamic"
            label="Comisiones generadas"
            :value="dinero(resumen.generado)"
          />
          <UiStat
            icon="flat-color-icons:ok"
            label="Comisiones pagadas"
            :value="dinero(resumen.pagado)"
          />
          <UiStat
            icon="flat-color-icons:alarm-clock"
            label="Pendientes"
            :value="dinero(resumen.pendiente)"
          />
        </div>
        <p v-else-if="!errorResumen" class="text-sm text-black/50">Cargando...</p>
      </UiCard>

      <UiCard title="Comisiones por conductor">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Conductor</span>
            <select
              v-model="conductorSeleccionado"
              class="min-w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            >
              <option :value="null">Selecciona un conductor</option>
              <option
                v-for="conductor in conductores"
                :key="conductor.id_conductor"
                :value="conductor.id_conductor"
              >
                {{ conductor.nombre }} {{ conductor.apellido_paterno }}
              </option>
            </select>
          </label>

          <button
            v-if="detalle"
            type="button"
            :disabled="!puedeLiquidar"
            class="rounded-lg bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-50"
            @click="liquidando = true"
          >
            Registrar liquidación
          </button>
        </div>

        <p v-if="mensajeLiquidacion" class="mb-4 text-sm text-green-600">
          {{ mensajeLiquidacion }}
        </p>
        <p v-if="errorDetalle" role="alert" class="mb-4 text-sm text-red-600">{{ errorDetalle }}</p>
        <p v-if="cargandoDetalle && !detalle" class="text-sm text-black/50">Cargando...</p>

        <template v-if="detalle">
          <div class="mb-6 flex flex-wrap gap-4">
            <UiStat
              icon="flat-color-icons:shipped"
              label="Viajes realizados"
              :value="detalle.resumen.viajes"
            />
            <UiStat
              icon="flat-color-icons:package"
              label="Importe de envíos"
              :value="dinero(detalle.resumen.importe_envios)"
            />
            <UiStat
              icon="flat-color-icons:settings"
              label="Comisión actual"
              :value="`${detalle.resumen.porcentaje_actual}%`"
            />
            <UiStat
              icon="flat-color-icons:positive-dynamic"
              label="Generado"
              :value="dinero(detalle.resumen.generado)"
            />
            <UiStat
              icon="flat-color-icons:ok"
              label="Pagado"
              :value="dinero(detalle.resumen.pagado)"
            />
            <UiStat
              icon="flat-color-icons:alarm-clock"
              label="Pendiente"
              :value="dinero(detalle.resumen.pendiente)"
            />
          </div>

          <h3 class="mb-2 text-sm font-semibold tracking-wide text-black/50 uppercase">Viajes</h3>
          <div class="mb-6 overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm">
              <thead>
                <tr
                  class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
                >
                  <th class="py-2 pr-4">Pedido</th>
                  <th class="py-2 pr-4">Entregado</th>
                  <th class="py-2 pr-4">Importe de envío</th>
                  <th class="py-2 pr-4">%</th>
                  <th class="py-2 pr-4">Comisión</th>
                  <th class="py-2 pr-4">Estado</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="detalle.viajes.length === 0">
                  <td colspan="6" class="py-6 text-center text-black/50">
                    Este conductor no tiene viajes con comisión.
                  </td>
                </tr>
                <tr
                  v-for="viaje in detalle.viajes"
                  v-else
                  :key="viaje.id_comision"
                  class="border-b border-gray-100 text-heading"
                >
                  <td class="py-2 pr-4 font-medium">
                    {{ viaje.numero_pedido ?? `#${viaje.id_pedido}` }}
                  </td>
                  <td class="py-2 pr-4">{{ fecha(viaje.fecha_entrega ?? viaje.created_at) }}</td>
                  <td class="py-2 pr-4">{{ dinero(viaje.importe_envio) }}</td>
                  <td class="py-2 pr-4">{{ viaje.porcentaje_aplicado }}%</td>
                  <td class="py-2 pr-4">{{ dinero(viaje.importe_comision) }}</td>
                  <td class="py-2 pr-4">
                    <UiBadge
                      :text="viaje.estado === 'PAGADA' ? 'Pagada' : 'Pendiente'"
                      :color="viaje.estado === 'PAGADA' ? 'green' : 'orange'"
                    />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <h3 class="mb-2 text-sm font-semibold tracking-wide text-black/50 uppercase">
            Liquidaciones
          </h3>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[560px] text-left text-sm">
              <thead>
                <tr
                  class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
                >
                  <th class="py-2 pr-4">Fecha</th>
                  <th class="py-2 pr-4">Monto</th>
                  <th class="py-2 pr-4">Referencia</th>
                  <th class="py-2 pr-4">Viajes cubiertos</th>
                  <th class="py-2 pr-4">Registró</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="detalle.liquidaciones.length === 0">
                  <td colspan="5" class="py-6 text-center text-black/50">
                    Todavía no se ha registrado ningún pago a este conductor.
                  </td>
                </tr>
                <tr
                  v-for="liquidacion in detalle.liquidaciones"
                  v-else
                  :key="liquidacion.id_liquidacion"
                  class="border-b border-gray-100 text-heading"
                >
                  <td class="py-2 pr-4">{{ fecha(liquidacion.created_at) }}</td>
                  <td class="py-2 pr-4 font-medium">{{ dinero(liquidacion.monto) }}</td>
                  <td class="py-2 pr-4">{{ liquidacion.referencia ?? '—' }}</td>
                  <td class="py-2 pr-4">{{ liquidacion.viajes_cubiertos ?? 0 }}</td>
                  <td class="py-2 pr-4">{{ liquidacion.registrado_por ?? '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </UiCard>
    </div>

    <LiquidarComisionModal
      v-if="liquidando && detalle"
      :slug="slug"
      :conductor="detalle.conductor"
      @cerrar="liquidando = false"
      @liquidado="onLiquidado"
    />
  </TenantLayout>
</template>
