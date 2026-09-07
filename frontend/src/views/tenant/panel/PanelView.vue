<script setup lang="ts">
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import TenantLayout from '@/layouts/TenantLayout.vue'
import ServiciosEnTurno from '@/components/panel/ServiciosEnTurno.vue'
import type { ViajeEnTurno } from '@/components/panel/ServiciosEnTurno.vue'
import MapaConductores from '@/components/panel/MapaConductores.vue'
import ConductoresActivos from '@/components/panel/ConductoresActivos.vue'
import NuevaEntregaPanel from '@/components/panel/NuevaEntregaPanel.vue'
import DetalleEnvioPanel from '@/components/panel/DetalleEnvioPanel.vue'
import { useRealtime } from '@/composables/useRealtime'

// Deja lista la conexión de tiempo real del tenant (spec tenant/018) — sin listeners todavía,
// eso lo agregan las specs 020/021 que la heredan.
useRealtime(useRoute().params.slug as string)

const layoutRef = ref<InstanceType<typeof TenantLayout>>()
const serviciosRef = ref<InstanceType<typeof ServiciosEnTurno>>()
const mapaRef = ref<InstanceType<typeof MapaConductores>>()
const nuevaEntregaAbierta = ref(false)
// Cuál viaje está abierto en el detalle vive aquí y no en ServiciosEnTurno: es el único punto que
// ve a los dos paneles deslizantes, y por eso el único que puede garantizar que nunca estén los dos
// abiertos a la vez (spec tenant/008).
const viajeSeleccionado = ref<ViajeEnTurno | null>(null)

function alternarNuevaEntrega() {
  nuevaEntregaAbierta.value = !nuevaEntregaAbierta.value
  if (nuevaEntregaAbierta.value) viajeSeleccionado.value = null
}

function cerrarNuevaEntrega() {
  nuevaEntregaAbierta.value = false
  layoutRef.value?.focusNuevaEntrega()
}

function onAgendado() {
  serviciosRef.value?.recargar()
  cerrarNuevaEntrega()
}

function onSeleccionarViaje(viaje: ViajeEnTurno) {
  viajeSeleccionado.value = viaje
  nuevaEntregaAbierta.value = false
}

function onViajeCancelado() {
  viajeSeleccionado.value = null
  serviciosRef.value?.recargar()
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
    <ServiciosEnTurno
      ref="serviciosRef"
      :seleccionado-id="viajeSeleccionado?.id_pedido ?? null"
      @seleccionar="onSeleccionarViaje"
    />
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
      @cerrar="viajeSeleccionado = null"
      @cancelado="onViajeCancelado"
    />
  </TenantLayout>
</template>
