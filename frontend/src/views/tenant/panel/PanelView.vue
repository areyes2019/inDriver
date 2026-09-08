<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import TenantLayout from '@/layouts/TenantLayout.vue'
import ServiciosEnTurno from '@/components/panel/ServiciosEnTurno.vue'
import MapaConductores from '@/components/panel/MapaConductores.vue'
import ConductoresActivos from '@/components/panel/ConductoresActivos.vue'
import NuevaEntregaPanel from '@/components/panel/NuevaEntregaPanel.vue'
import DetalleEnvioPanel from '@/components/panel/DetalleEnvioPanel.vue'
import { usePanelStore, type ViajeEnTurno } from '@/stores/panel'
import realtimeService from '@/services/realtime'

const slug = useRoute().params.slug as string

// Un solo dueño del estado del Panel y una sola suscripción al canal (spec tenant/027, RN-02 y
// RN-04): antes cada componente pedía sus datos y ataba sus propios listeners, y un mismo evento
// disparaba tres recargas contra el mismo limitador.
const panel = usePanelStore()

onMounted(() => panel.iniciar(slug))
onUnmounted(() => {
  panel.detener()
  realtimeService.unsubscribe()
})

const layoutRef = ref<InstanceType<typeof TenantLayout>>()
const mapaRef = ref<InstanceType<typeof MapaConductores>>()
const nuevaEntregaAbierta = ref(false)
// Cuál viaje está abierto en el detalle vive aquí y no en ServiciosEnTurno: es el único punto que
// ve a los dos paneles deslizantes, y por eso el único que puede garantizar que nunca estén los dos
// abiertos a la vez (spec tenant/008).
const idViajeSeleccionado = ref<number | null>(null)

// El detalle lee del store y no de una copia congelada al hacer clic (spec tenant/027, RN-26): así
// el envío abierto refleja en vivo su cambio de estado, y si termina se queda visible hasta que la
// persona lo cierre en vez de desaparecerle de las manos.
const ultimoViajeVisto = ref<ViajeEnTurno | null>(null)

const viajeSeleccionado = computed<ViajeEnTurno | null>(() => {
  if (idViajeSeleccionado.value === null) return null

  // Un envío que termina sale de la lista, pero el detalle abierto se queda con la última versión
  // que vio —ya con su estado final— hasta que la persona lo cierre (RN-26).
  return panel.viajes.get(idViajeSeleccionado.value) ?? ultimoViajeVisto.value
})

watch(viajeSeleccionado, (viaje) => {
  if (viaje) ultimoViajeVisto.value = viaje
})

function alternarNuevaEntrega() {
  nuevaEntregaAbierta.value = !nuevaEntregaAbierta.value
  if (nuevaEntregaAbierta.value) idViajeSeleccionado.value = null
}

function cerrarNuevaEntrega() {
  nuevaEntregaAbierta.value = false
  layoutRef.value?.focusNuevaEntrega()
}

// Ya no hay que recargar nada: el envío recién agendado entra a la lista por `pedido.disponible`
// (spec tenant/024) y el cancelado sale por `pedido.cancelado`.
function onAgendado() {
  cerrarNuevaEntrega()
}

function onSeleccionarViaje(viaje: ViajeEnTurno) {
  ultimoViajeVisto.value = viaje
  idViajeSeleccionado.value = viaje.id_pedido
  nuevaEntregaAbierta.value = false
}

function onViajeCancelado() {
  idViajeSeleccionado.value = null
}

// El panel de flotilla tapa o destapa un 20% del mapa al colapsarse: Google no se entera solo del
// cambio de tamaño y dejaría esa franja en gris (spec tenant/023).
function onColapsoTerminado() {
  mapaRef.value?.redimensionar()
}
</script>

<template>
  <TenantLayout
    ref="layoutRef"
    ancho-completo
    :nueva-entrega-abierta="nuevaEntregaAbierta"
    @toggle-nueva-entrega="alternarNuevaEntrega"
  >
    <!-- Columna izquierda (viajes en turno), fija sobre el 20% izquierdo: tenant/008-servicios.md, tenant/012-datos-reales-servicios-en-turno.md -->
    <ServiciosEnTurno :seleccionado-id="idViajeSeleccionado" @seleccionar="onSeleccionarViaje" />
    <!-- Mapa de fondo, a toda la ventana bajo la navbar; los dos paneles flotan encima: tenant/009-mapa.md, tenant/023-rediseno-panel-flotilla.md -->
    <div class="h-[calc(100vh-4.25rem)] w-full">
      <MapaConductores ref="mapaRef" />
    </div>
    <!-- Columna derecha (flotilla), fija y colapsable sobre el 20% derecho: tenant/023-rediseno-panel-flotilla.md -->
    <ConductoresActivos @colapso-terminado="onColapsoTerminado" />
    <!-- Panel deslizante de agendamiento rápido, se superpone a la columna izquierda al abrir: tenant/006-crud-pedidos.md -->
    <NuevaEntregaPanel
      :abierto="nuevaEntregaAbierta"
      @cerrar="cerrarNuevaEntrega"
      @agendado="onAgendado"
    />
    <!-- Panel deslizante con el detalle del viaje seleccionado, sobre la columna izquierda: tenant/008-servicios.md -->
    <DetalleEnvioPanel
      :viaje="viajeSeleccionado"
      @cerrar="idViajeSeleccionado = null"
      @cancelado="onViajeCancelado"
    />
  </TenantLayout>
</template>
