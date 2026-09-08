<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiEstadoConexion from '@/components/ui/UiEstadoConexion.vue'
import { usePanelStore, type ConductorActivo } from '@/stores/panel'
import { useOrdenEstable } from '@/composables/useOrdenEstable'

/**
 * Flotilla en línea (specs tenant/014, tenant/023, tenant/027).
 *
 * Desde la spec tenant/027 no pide datos ni escucha el canal: lee de `usePanelStore`. Antes
 * recargaba la lista entera con cinco eventos distintos —a la vez que `MapaConductores` hacía lo
 * mismo por su cuenta contra el mismo endpoint—, lo que agotaba el limitador y dejaba el panel en
 * "No se pudo cargar" justo cuando más se movía la operación.
 */

interface Toast {
  id: string
  texto: string
}

const emit = defineEmits<{ 'colapso-terminado': [] }>()

const panel = usePanelStore()

const toasts = ref<Toast[]>([])
// Estado local a propósito (spec tenant/023): ahora que el mapa ocupa la pantalla completa y ya no
// calcula márgenes según el ancho de este panel, nadie más necesita saber si está colapsado.
const colapsado = ref(false)

const {
  elementos: conductores,
  congelar,
  descongelar,
} = useOrdenEstable(
  () => panel.conductoresOrdenados,
  (conductor) => conductor.id_conductor,
)

function inicial(nombre: string): string {
  return nombre.trim().charAt(0).toUpperCase()
}

/**
 * `vehiculos` solo conserva `placa` y `marca`: `modelo`, `anio` y `color` se eliminaron en la
 * migración del 4 de septiembre, así que no hay más que mostrar aquí.
 */
function textoVehiculo(conductor: ConductorActivo): string {
  if (!conductor.placa) return 'Sin vehículo'

  return conductor.marca ? `${conductor.marca} ${conductor.placa}` : conductor.placa
}

function textoSaldo(viajes: number): string {
  return `Saldo: ${viajes} ${viajes === 1 ? 'viaje' : 'viajes'}`
}

/**
 * El badge NO sale de `conductores.disponibilidad`: la app del repartidor solo escribe DISPONIBLE
 * al conectarse y FUERA_DE_SERVICIO al desconectarse (spec tenant/013), nunca OCUPADO — pintar ese
 * enum diría "Disponible" para alguien que va manejando con el paquete encima. La verdad de si está
 * ocupado es traer un pedido en curso (spec tenant/023).
 */
function badge(conductor: ConductorActivo): { texto: string; color: 'green' | 'orange' } {
  return conductor.pedido_asignado
    ? { texto: 'Ocupado', color: 'orange' }
    : { texto: 'Disponible', color: 'green' }
}

function mostrarToast(texto: string) {
  const id = crypto.randomUUID()
  toasts.value.push({ id, texto })
  setTimeout(() => {
    toasts.value = toasts.value.filter((toast) => toast.id !== id)
  }, 4000)
}

// El aviso de "se puso en línea" (spec tenant/019) sigue siendo cosa de la vista: el store solo
// anuncia quién entró y aquí se decide cómo se ve.
watch(
  () => panel.ultimaConexionDeConductor,
  (conexion) => {
    if (conexion) mostrarToast(`${conexion.nombre} está en línea`)
  },
)

function sinAnimacion(): boolean {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

function alternarColapso() {
  colapsado.value = !colapsado.value

  // Con movimiento reducido la transición no ocurre y `transitionend` nunca llegaría, así que el
  // mapa se quedaría sin remedirse: se avisa en cuanto el DOM refleja el cambio.
  if (sinAnimacion()) {
    nextTick(() => emit('colapso-terminado'))
  }
}

function onTransitionEnd(event: TransitionEvent) {
  if (event.propertyName === 'transform' && event.target === event.currentTarget) {
    emit('colapso-terminado')
  }
}
</script>

<template>
  <aside
    class="panel-deslizante fixed right-0 top-[4.25rem] z-30 flex h-[calc(100vh-4.25rem)] w-[20%] flex-col bg-white shadow-xl transition-transform duration-[400ms] ease-in-out"
    :class="colapsado ? 'translate-x-full' : 'translate-x-0'"
    @transitionend="onTransitionEnd"
  >
    <!--
      Vive fuera del borde izquierdo del panel (`-translate-x-full`), así que cuando el panel se va
      a la derecha este botón queda pegado al borde de la ventana y hace de pestaña de reapertura:
      un solo control para las dos direcciones, sin dejar al usuario sin salida.
    -->
    <button
      type="button"
      :aria-expanded="!colapsado"
      :aria-label="colapsado ? 'Mostrar panel de flotilla' : 'Ocultar panel de flotilla'"
      class="absolute left-0 top-3 flex h-9 w-9 -translate-x-full items-center justify-center rounded-l-lg border border-r-0 border-default bg-white text-body shadow-md transition-colors hover:bg-black/5 focus:outline-none focus:ring-2 focus:ring-accent"
      @click="alternarColapso"
    >
      <svg
        class="h-4 w-4 transition-transform duration-[400ms]"
        :class="colapsado ? 'rotate-180' : ''"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <path d="m9 18 6-6-6-6" />
      </svg>
    </button>

    <header class="flex items-center justify-between gap-2 border-b border-default px-4 py-3">
      <div class="flex min-w-0 items-center gap-2">
        <Icon icon="flat-color-icons:automotive" width="18" height="18" aria-hidden="true" />
        <h2 class="truncate text-xs font-semibold uppercase tracking-wide text-body">Flotilla</h2>
        <UiEstadoConexion punto :estado="panel.conexion" :desincronizado="panel.desincronizado" />
      </div>
      <!-- RN-24: el contador cambia con una transición en vez de saltar de un número a otro. -->
      <Transition name="contador" mode="out-in">
        <span
          :key="panel.enLinea"
          class="shrink-0 rounded-full border border-success-text/20 bg-success-bg px-2.5 py-1 text-xs font-semibold text-success-text"
        >
          {{ panel.enLinea }} en línea
        </span>
      </Transition>
    </header>

    <UiEstadoConexion
      :estado="panel.conexion"
      :desincronizado="panel.desincronizado"
      @actualizar="panel.sincronizar()"
    />

    <div class="flex-1 overflow-y-auto px-4" @pointerenter="congelar" @pointerleave="descongelar">
      <!-- RN-16: esqueleto solo mientras no hay nada; después la lista ya no se vacía nunca. -->
      <ul v-if="!panel.hayDatos" class="flex flex-col" aria-hidden="true">
        <li
          v-for="n in 3"
          :key="n"
          class="flex animate-pulse items-start gap-3 border-b border-default py-3"
        >
          <span class="h-9 w-9 shrink-0 rounded-full bg-slate-200"></span>
          <div class="flex-1 space-y-2">
            <div class="h-3 w-2/3 rounded bg-slate-200"></div>
            <div class="h-3 w-1/2 rounded bg-slate-100"></div>
          </div>
        </li>
      </ul>

      <p v-else-if="conductores.length === 0" class="py-4 text-sm text-body">
        No hay conductores activos
      </p>

      <TransitionGroup v-else tag="ul" name="lista" class="flex flex-col">
        <li
          v-for="conductor in conductores"
          :key="conductor.id_conductor"
          class="flex items-start gap-3 border-b border-default py-3 transition-colors duration-300 last:border-b-0"
          :class="
            panel.estaResaltado(`conductor:${conductor.id_conductor}`) ? 'bg-accent-soft' : ''
          "
        >
          <span
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-sm font-semibold text-accent"
            aria-hidden="true"
          >
            {{ inicial(conductor.nombre) }}
          </span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-heading">{{ conductor.nombre }}</p>
            <p class="truncate text-sm font-semibold text-success-text">
              {{ textoSaldo(conductor.saldo_viajes) }}
            </p>
            <p class="truncate text-xs text-body/70">{{ textoVehiculo(conductor) }}</p>
          </div>
          <UiBadge :text="badge(conductor).texto" :color="badge(conductor).color" />
        </li>
      </TransitionGroup>
    </div>
  </aside>

  <!--
    Hermano del `<aside>`, no hijo: un ancestro con `transform` pasa a ser el bloque contenedor de
    sus descendientes `position: fixed`, así que dentro del panel los toasts se irían con él al
    colapsarlo en vez de quedarse anclados a la ventana.
  -->
  <div
    class="pointer-events-none fixed bottom-4 z-40 flex flex-col gap-2 transition-[right] duration-[400ms] ease-in-out"
    :class="colapsado ? 'right-4' : 'right-[calc(20%+1rem)]'"
  >
    <TransitionGroup name="lista">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        class="pointer-events-auto rounded bg-heading px-4 py-2 text-sm text-white shadow-lg"
      >
        {{ toast.texto }}
      </div>
    </TransitionGroup>
  </div>
</template>

<style scoped>
/* RN-21: entrada, salida y reacomodo animados, con la misma gramática que "Viajes en turno". */
.lista-enter-active,
.lista-leave-active,
.lista-move {
  transition:
    opacity 200ms ease,
    transform 300ms ease;
}

.lista-enter-from {
  opacity: 0;
  transform: translateX(12px);
}

.lista-leave-to {
  opacity: 0;
  transform: translateX(12px);
}

.lista-leave-active {
  position: absolute;
  width: calc(100% - 2rem);
}

.contador-enter-active,
.contador-leave-active {
  transition:
    opacity 150ms ease,
    transform 150ms ease;
}

.contador-enter-from {
  opacity: 0;
  transform: translateY(-4px);
}

.contador-leave-to {
  opacity: 0;
  transform: translateY(4px);
}

/* RN-27: se respeta la preferencia de movimiento reducido del sistema operativo. */
@media (prefers-reduced-motion: reduce) {
  .lista-enter-active,
  .lista-leave-active,
  .lista-move,
  .contador-enter-active,
  .contador-leave-active {
    transition: none;
  }

  .lista-enter-from,
  .lista-leave-to,
  .contador-enter-from,
  .contador-leave-to {
    transform: none;
  }
}
</style>
