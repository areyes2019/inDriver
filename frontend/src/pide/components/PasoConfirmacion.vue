<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { computed, nextTick, onMounted, ref } from 'vue'
import MapService from '@/services/maps/MapService'
import { api } from '../api'
import { fechaCorta, km, pesos, tituloModalidad } from '../lib/formato'
import { useAsistenteStore } from '../stores/asistente'
import { useMarcaStore } from '../stores/marca'
import MapaCliente from './MapaCliente.vue'

/** Paso 4 (6.3): resumen visual con el precio que calcula el servidor (RN-21, RN-22). */
const emit = defineEmits<{ volverA: [paso: 1 | 2] }>()
const asistente = useAsistenteStore()
const marca = useMarcaStore()
const ID = 'mapa-confirmacion'

const cotizando = ref(false)
const error = ref<string | null>(null)
const mapaListo = ref(false)

const c = computed(() => asistente.cotizacion)
const s = computed(() => asistente.solicitud)

async function cotizar(): Promise<void> {
  if (!s.value) return
  cotizando.value = true
  error.value = null
  try {
    const r = await api.cotizar(s.value)
    if (r.ok) {
      asistente.cotizacion = r.cotizacion
    } else if (r.motivo === 'FUERA_DE_ZONA') {
      error.value = 'Por ahora no llegamos a esta dirección'
      emit('volverA', r.punto === 'recogida' ? 1 : 2)
    } else if (r.motivo === 'SIN_TARIFAS') {
      // RN-26: la flotilla todavía no configura sus tarifas.
      error.value = `${marca.nombre} todavía no tiene precios en la app. Llámanos para pedir tu envío.`
    } else {
      error.value = 'No pudimos calcular la ruta. Revisa las direcciones o intenta en un momento.'
    }
  } finally {
    cotizando.value = false
  }
}

async function dibujar(ok: boolean): Promise<void> {
  mapaListo.value = ok
  if (!ok || !s.value) return
  await nextTick()
  const { recogida, entrega } = s.value
  MapService.addMarker(ID, 'recogida', recogida, { color: '#16A34A', title: 'Recogida' })
  MapService.addMarker(ID, 'entrega', entrega, { color: '#DC2626', title: 'Entrega' })
  await MapService.drawRoute(ID, 'ruta', [recogida, entrega], { color: '#6D28D9' })
}

onMounted(() => {
  if (!c.value) void cotizar()
})
</script>

<template>
  <div v-if="s" class="space-y-4">
    <div class="h-48 overflow-hidden rounded-3xl ring-1 ring-linea">
      <MapaCliente :id="ID" :centro="s.recogida" @listo="dibujar" />
    </div>

    <ol class="relative space-y-4 rounded-3xl bg-white p-4 ring-1 ring-linea">
      <span class="absolute top-9 bottom-9 left-[1.65rem] w-0.5 bg-linea" aria-hidden="true" />
      <li class="relative flex gap-3">
        <span class="z-10 mt-1 size-4 shrink-0 rounded-full border-4 border-exito bg-white" />
        <div class="min-w-0">
          <p class="text-xs font-semibold tracking-wide text-suave uppercase">Recogida</p>
          <p class="text-sm text-tinta">{{ s.recogida.direccion }}</p>
          <p class="text-xs text-suave">{{ s.contactoRecogida.nombre }}</p>
        </div>
      </li>
      <li class="relative flex gap-3">
        <span class="z-10 mt-1 size-4 shrink-0 rounded-full border-4 border-peligro bg-white" />
        <div class="min-w-0">
          <p class="text-xs font-semibold tracking-wide text-suave uppercase">Entrega</p>
          <p class="text-sm text-tinta">{{ s.entrega.direccion }}</p>
          <p class="text-xs text-suave">Recibe {{ s.recibe.nombre }}</p>
        </div>
      </li>
    </ol>

    <div class="rounded-3xl bg-white p-4 ring-1 ring-linea">
      <div v-if="cotizando" class="space-y-3" aria-busy="true" aria-label="Calculando el costo">
        <div class="h-4 w-2/3 animate-pulse rounded bg-linea" />
        <div class="h-4 w-1/2 animate-pulse rounded bg-linea" />
        <div class="h-6 w-1/3 animate-pulse rounded bg-linea" />
      </div>

      <div v-else-if="error" class="text-center">
        <p class="text-sm text-peligro">{{ error }}</p>
        <button type="button" class="mt-2 min-h-11 font-semibold text-morado-700" @click="cotizar">
          Intentar de nuevo
        </button>
      </div>

      <dl v-else-if="c" class="space-y-2.5 text-sm">
        <div class="flex gap-4 text-suave">
          <span class="flex items-center gap-1.5"
            ><Icon icon="tabler:route" class="size-4" /> {{ km(c.km) }}</span
          >
          <span class="flex items-center gap-1.5"
            ><Icon icon="tabler:clock" class="size-4" /> ~{{ c.minutos }} min</span
          >
          <span v-if="!s.loAntesPosible && s.fecha && s.hora" class="flex items-center gap-1.5">
            <Icon icon="tabler:calendar" class="size-4" />
            {{ fechaCorta(`${s.fecha}T${s.hora}:00`) }}
          </span>
        </div>
        <div class="flex justify-between">
          <dt class="text-suave">Envío</dt>
          <dd class="font-medium">{{ pesos(c.importeEnvio) }}</dd>
        </div>
        <div v-if="c.importeProducto" class="flex justify-between">
          <dt class="text-suave">Producto</dt>
          <dd class="font-medium">{{ pesos(c.importeProducto) }}</dd>
        </div>
        <div class="flex items-end justify-between border-t border-linea pt-2.5">
          <dt class="font-semibold">Total</dt>
          <dd class="font-titulo text-3xl font-extrabold text-morado-700">{{ pesos(c.total) }}</dd>
        </div>
        <div class="flex items-center gap-2 rounded-2xl bg-morado-50 p-3 text-tinta">
          <Icon icon="tabler:cash" class="size-5 shrink-0 text-exito" />
          <span>
            Efectivo · <strong>{{ tituloModalidad(s.modalidad) }}</strong>
          </span>
        </div>
        <p
          v-if="s.modalidad === 'RECEPTOR_PAGA_ENVIO_PRODUCTOS'"
          class="flex gap-2 rounded-2xl bg-amarillo-100 p-3 text-xs text-tinta"
        >
          <Icon icon="tabler:info-circle" class="size-4 shrink-0 text-morado-700" />
          Si quien recibe no paga, el paquete regresa a la recogida y el regreso tiene un costo
          extra.
        </p>
      </dl>
    </div>
  </div>
</template>
