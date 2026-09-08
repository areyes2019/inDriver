<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { usePanelStore, type ViajeEnTurno } from '@/stores/panel'
import { useOrdenEstable } from '@/composables/useOrdenEstable'
import UiEstadoConexion from '@/components/ui/UiEstadoConexion.vue'

/**
 * Lista de envíos vivos del tenant (specs tenant/008, tenant/012, tenant/024, tenant/027).
 *
 * Desde la spec tenant/027 este componente no pide datos ni escucha el canal: los lee de
 * `usePanelStore`, que es el único que habla con el servidor. Antes recorría **todas** las páginas
 * de `GET /pedidos` en cada evento de tiempo real —historial completo incluido— y vaciaba la lista
 * mientras tanto; de ahí el parpadeo y el 429 que la dejaba en "No se pudo cargar".
 */

withDefaults(defineProps<{ seleccionadoId?: number | null }>(), { seleccionadoId: null })

const emit = defineEmits<{ seleccionar: [viaje: ViajeEnTurno] }>()

const panel = usePanelStore()

// RN-25: el orden se congela mientras el puntero está encima, para que ninguna fila se mueva justo
// debajo del clic. El contenido de cada fila sí sigue actualizándose.
const {
  elementos: viajes,
  congelar,
  descongelar,
} = useOrdenEstable(
  () => panel.viajesOrdenados,
  (viaje) => viaje.id_pedido,
)

// El backend manda `fecha_servicio` ('YYYY-MM-DD') y `hora_desde` por separado; la etiqueta
// "sáb 5 de sept 09:41 a.m." se compone aquí (spec tenant/008). Se acepta la abreviatura de mes que
// devuelve Intl en español ('sept'), sin mapear los doce meses a mano.
const formatoFecha = new Intl.DateTimeFormat('es-MX', {
  weekday: 'short',
  day: 'numeric',
  month: 'short',
})
const formatoHora = new Intl.DateTimeFormat('es-MX', {
  hour: '2-digit',
  minute: '2-digit',
  hour12: true,
})

function construirFecha(viaje: ViajeEnTurno): Date | null {
  if (!viaje.fecha_servicio) return null
  const hora = (viaje.hora_desde ?? '00:00').slice(0, 5)
  const fecha = new Date(`${viaje.fecha_servicio}T${hora}:00`)
  return Number.isNaN(fecha.getTime()) ? null : fecha
}

function etiquetaFecha(viaje: ViajeEnTurno): string {
  if (viaje.lo_antes_posible) return 'Lo antes posible'

  const fecha = construirFecha(viaje)
  if (fecha === null) return viaje.hora_desde ?? ''

  const partes = Object.fromEntries(
    formatoFecha.formatToParts(fecha).map((parte) => [parte.type, parte.value]),
  )
  return `${partes.weekday} ${partes.day} de ${partes.month} ${formatoHora.format(fecha)}`
}

/** El estado que el despachador ve en la tarjeta mientras el envío camina. */
const ETIQUETAS_ESTADO: Record<string, string> = {
  PENDIENTE: 'Por asignar',
  PUBLICADO: 'Buscando conductor',
  TOMADO: 'Asignado',
  ARRIBADO: 'En recogida',
  EN_CAMINO: 'En camino',
  ARRIBADO_A_ENTREGA: 'En entrega',
}
</script>

<template>
  <aside
    class="fixed left-0 top-[4.25rem] z-30 flex h-[calc(100vh-4.25rem)] w-[20%] flex-col bg-white shadow-xl"
  >
    <header class="flex items-center gap-2 border-b border-default bg-white px-5 py-4">
      <h2 class="text-base font-semibold text-heading">Viajes en turno</h2>
      <UiEstadoConexion punto :estado="panel.conexion" :desincronizado="panel.desincronizado" />
    </header>

    <UiEstadoConexion
      :estado="panel.conexion"
      :desincronizado="panel.desincronizado"
      @actualizar="panel.sincronizar()"
    />

    <div
      class="flex-1 overflow-y-auto bg-slate-50 p-4"
      @pointerenter="congelar"
      @pointerleave="descongelar"
    >
      <!-- RN-16: el esqueleto es solo de la primera carga. Después la lista ya nunca se vacía. -->
      <ul v-if="!panel.hayDatos" class="flex flex-col gap-3" aria-hidden="true">
        <li v-for="n in 3" :key="n" class="animate-pulse rounded-lg bg-white p-3 shadow-md">
          <div class="h-3 w-2/3 rounded bg-slate-200"></div>
          <div class="mt-3 h-3 w-full rounded bg-slate-100"></div>
          <div class="mt-2 h-3 w-4/5 rounded bg-slate-100"></div>
        </li>
      </ul>

      <p v-else-if="viajes.length === 0" class="text-sm text-body">No hay viajes en turno</p>

      <TransitionGroup v-else tag="ul" name="lista" class="flex flex-col gap-3">
        <li v-for="viaje in viajes" :key="viaje.id_pedido">
          <button
            type="button"
            class="w-full rounded-lg border-l-4 border-accent bg-white p-3 text-left shadow-md transition-all duration-300 hover:shadow-hover focus:outline-none focus:ring-2 focus:ring-accent"
            :class="[
              viaje.id_pedido === seleccionadoId ? 'ring-2 ring-accent' : '',
              panel.estaResaltado(`viaje:${viaje.id_pedido}`) ? 'bg-accent-soft' : '',
              panel.estaSaliendo(viaje.id_pedido) ? 'border-emerald-500 opacity-60' : '',
            ]"
            @click="emit('seleccionar', viaje)"
          >
            <div class="flex items-center justify-between gap-2">
              <span class="flex min-w-0 items-center gap-1.5">
                <Icon
                  icon="flat-color-icons:calendar"
                  width="18"
                  height="18"
                  aria-hidden="true"
                  class="shrink-0"
                />
                <span class="truncate text-sm font-semibold text-heading">
                  #{{ viaje.numero_pedido }}
                </span>
              </span>
              <span class="shrink-0 text-xs font-medium text-accent">
                {{ etiquetaFecha(viaje) }}
              </span>
            </div>

            <div class="mt-2 flex min-w-0 items-center gap-2">
              <span class="h-2 w-2 shrink-0 rounded-full bg-blue-500" aria-hidden="true"></span>
              <p class="truncate text-sm text-heading">{{ viaje.direccion_recogida }}</p>
            </div>
            <div class="mt-1 flex min-w-0 items-center gap-2">
              <span class="h-2 w-2 shrink-0 rounded-full bg-red-500" aria-hidden="true"></span>
              <p class="truncate text-sm text-heading">{{ viaje.direccion_entrega }}</p>
            </div>

            <!--
              RN-23: el viaje entregado se despide en vez de evaporarse. El resto del tiempo la línea
              dice en qué va y quién lo trae, que es lo que antes obligaba a abrir el detalle.
            -->
            <p
              v-if="panel.estaSaliendo(viaje.id_pedido)"
              class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-emerald-600"
            >
              <Icon icon="mdi:check-circle" width="14" height="14" aria-hidden="true" />
              Entregado
            </p>
            <p
              v-else-if="ETIQUETAS_ESTADO[viaje.estado]"
              class="mt-2 truncate text-xs text-body/70"
            >
              {{ ETIQUETAS_ESTADO[viaje.estado] }}
              <span v-if="viaje.conductor_nombre"> · {{ viaje.conductor_nombre }}</span>
            </p>
          </button>
        </li>
      </TransitionGroup>
    </div>
  </aside>
</template>

<style scoped>
/*
  RN-21: entrada, salida y reacomodo animados. `lista-leave-active` se saca del flujo para que las
  filas de abajo suban con la transición de `move` en vez de dar un salto cuando el hueco se cierra.
*/
.lista-enter-active,
.lista-leave-active,
.lista-move {
  transition:
    opacity 200ms ease,
    transform 300ms ease;
}

.lista-enter-from {
  opacity: 0;
  transform: translateX(-12px);
}

.lista-leave-to {
  opacity: 0;
  transform: translateX(12px);
}

.lista-leave-active {
  position: absolute;
  width: calc(100% - 2rem);
}

/* RN-27: con movimiento reducido no hay desplazamientos; el cambio simplemente ocurre. */
@media (prefers-reduced-motion: reduce) {
  .lista-enter-active,
  .lista-leave-active,
  .lista-move {
    transition: none;
  }

  .lista-enter-from,
  .lista-leave-to {
    transform: none;
  }
}
</style>
