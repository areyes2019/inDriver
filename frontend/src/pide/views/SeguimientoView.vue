<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { iniciales } from '@/lib/iniciales'
import MapService from '@/services/maps/MapService'
import { api } from '../api'
import { YaAsignado } from '../api/real'
import type { ActualizacionPedido, Coordenada, Pedido } from '../api/tipos'
import BotonPrincipal from '../components/BotonPrincipal.vue'
import HojaInferior from '../components/HojaInferior.vue'
import LineaProgreso from '../components/LineaProgreso.vue'
import MapaCliente from '../components/MapaCliente.vue'
import TarjetaRepartidor from '../components/TarjetaRepartidor.vue'
import {
  clientePuedeCancelar,
  estaBuscando,
  grupoDe,
  mensajeDe,
  muestraRepartidor,
  tramoDe,
} from '../lib/estados'
import { fechaCorta, minutosDeTexto, pesos } from '../lib/formato'
import { minutosRestantes } from '../lib/geo'
import { formatoTelefono } from '../lib/telefono'
import { useMarcaStore } from '../stores/marca'
import { useUiStore } from '../stores/ui'

/**
 * Pedido en curso (6.4 y 6.5): "buscando repartidor" mientras nadie acepta, y seguimiento en vivo
 * después. Escucha el canal del cliente (RN-41) y, si la conexión se corta, consulta cada 15 s
 * (RN-47).
 */
const route = useRoute()
const router = useRouter()
const marca = useMarcaStore()
const ui = useUiStore()

const ID = 'mapa-seguimiento'
const RESPALDO_MS = 15_000
const id = Number(route.params.id)

const pedido = ref<Pedido | null>(null)
const mapaListo = ref(false)
const minutos = ref<number | null>(null)
const confirmarCancelacion = ref(false)
const cancelando = ref(false)

let trazo: Coordenada[] = []
let minutosTramo = 0
let tramoDibujado: 'recogida' | 'entrega' | null = null
let motoPuesta = false
let dejarDeEscuchar: (() => void) | null = null
let respaldo: ReturnType<typeof setInterval> | null = null

const reconectando = computed(() => api.estadoConexion() === 'reconectando')
const buscando = computed(() => (pedido.value ? estaBuscando(pedido.value.estado) : false))
const enCurso = computed(() => (pedido.value ? muestraRepartidor(pedido.value.estado) : false))
const terminado = computed(() =>
  pedido.value ? grupoDe(pedido.value.estado) !== 'activos' : false,
)
const entregado = computed(
  () => pedido.value?.estado === 'ENTREGADO' || pedido.value?.estado === 'DEVUELTO',
)
const total = computed(() =>
  pedido.value ? pedido.value.importeEnvio + pedido.value.importeProducto : 0,
)

onMounted(async () => {
  const datos = await api.pedido(id)
  if (!datos) {
    ui.avisar('No encontramos ese pedido', 'error')
    router.replace({ name: 'pedidos', params: { slug: marca.slug } })
    return
  }
  if (grupoDe(datos.estado) !== 'activos') {
    router.replace({ name: 'detalle', params: { slug: marca.slug, id } })
    return
  }
  pedido.value = datos
  dejarDeEscuchar = api.escucharPedido(id, aplicar)
})

onBeforeUnmount(() => {
  dejarDeEscuchar?.()
  detenerRespaldo()
})

function aplicar(cambio: ActualizacionPedido): void {
  const p = pedido.value
  if (!p) return
  const estadoAntes = p.estado
  if (cambio.estado) p.estado = cambio.estado
  if (cambio.repartidor !== undefined) p.repartidor = cambio.repartidor
  if (cambio.sinRepartidor !== undefined) p.sinRepartidor = cambio.sinRepartidor
  if (cambio.posicion) p.posicion = cambio.posicion

  if (!mapaListo.value) return
  if (p.estado !== estadoAntes) void alCambiarEstado()
  if (cambio.posicion) moverMoto(cambio.posicion)
}

// ---- Mapa ----------------------------------------------------------------------------------

async function alListo(ok: boolean): Promise<void> {
  mapaListo.value = ok
  // El mapa se monta de nuevo al pasar de "buscando" a "seguimiento": todo se dibuja desde cero.
  tramoDibujado = null
  motoPuesta = false
  if (!ok || !pedido.value) return
  await nextTick()
  const p = pedido.value
  MapService.addMarker(ID, 'recogida', p.recogida, { color: '#16A34A', title: 'Recogida' })
  MapService.addMarker(ID, 'entrega', p.entrega, { color: '#DC2626', title: 'Entrega' })
  await alCambiarEstado()
  if (p.posicion) moverMoto(p.posicion)
}

async function alCambiarEstado(): Promise<void> {
  const p = pedido.value
  if (!p) return
  const tramo = tramoDe(p.estado)
  if (!tramo) {
    MapService.clearRoute(ID, 'ruta')
    tramoDibujado = null
    minutos.value = null
    return
  }
  // RN-43: una ruta por tramo, no una por cada posición.
  if (tramo !== tramoDibujado) await dibujarTramo(tramo)
}

async function dibujarTramo(tramo: 'recogida' | 'entrega'): Promise<void> {
  const p = pedido.value
  if (!p) return
  tramoDibujado = tramo
  const desde = tramo === 'recogida' ? (p.posicion ?? p.recogida) : p.recogida
  const hasta = tramo === 'recogida' ? p.recogida : p.entrega
  const r = await MapService.drawRoute(ID, 'ruta', [desde, hasta], {
    color: '#6D28D9',
    estilo: tramo === 'recogida' ? 'GUIONES' : 'SOLIDO',
  })
  trazo = r?.path ?? [desde, hasta]
  minutosTramo = (r && minutosDeTexto(r.duration)) ?? Math.round(((r?.distanceKm ?? 2) / 22) * 60)
  api.pistaDeTrazo?.(p.id, tramo, trazo)
  minutos.value = minutosTramo
}

function moverMoto(posicion: Coordenada): void {
  const p = pedido.value
  if (!p?.repartidor || !muestraRepartidor(p.estado)) return
  if (MapService.getCenter(ID) === null) return
  const opciones = {
    color: p.repartidor.color,
    iniciales: iniciales(p.repartidor.nombre),
    title: p.repartidor.nombre,
  }
  if (!motoPuesta) {
    MapService.addMarker(ID, 'moto', posicion, opciones)
    motoPuesta = true
  } else {
    MapService.updateMarker(ID, 'moto', posicion)
  }
  MapService.trimRoute(ID, 'ruta', posicion)
  if (trazo.length > 1) minutos.value = minutosRestantes(trazo, posicion, minutosTramo)
}

// ---- Respaldo cuando se corta la conexión (RN-47) -------------------------------------------

async function consultar(): Promise<void> {
  if (document.hidden) return
  const datos = await api.pedido(id)
  if (!datos || !pedido.value) return
  aplicar({
    idPedido: id,
    estado: datos.estado,
    repartidor: datos.repartidor,
    sinRepartidor: datos.sinRepartidor,
    ...(datos.posicion ? { posicion: datos.posicion } : {}),
  })
}

function detenerRespaldo(): void {
  if (respaldo) clearInterval(respaldo)
  respaldo = null
}

watch(
  reconectando,
  (si) => {
    detenerRespaldo()
    if (si) respaldo = setInterval(consultar, RESPALDO_MS)
    else void consultar()
  },
  { immediate: true },
)

// ---- Cancelación (RN-36, RN-37) -------------------------------------------------------------

async function cancelar(): Promise<void> {
  if (!pedido.value) return
  cancelando.value = true
  try {
    const p = await api.cancelar(pedido.value.id)
    aplicar({ idPedido: p.id, estado: 'CANCELADO' })
    confirmarCancelacion.value = false
  } catch (e) {
    if (e instanceof YaAsignado) {
      // RN-37: un repartidor lo tomó justo antes; ahora se ve "Llama a la flotilla".
      confirmarCancelacion.value = false
      aplicar({ idPedido: e.pedido.id, estado: e.pedido.estado, repartidor: e.pedido.repartidor })
      ui.avisar('Un repartidor ya tomó tu pedido. Para cancelarlo, llama a la flotilla.', 'error')
      return
    }
    ui.avisar('No pudimos cancelar. Intenta de nuevo.', 'error')
  } finally {
    cancelando.value = false
  }
}

function inicio(): void {
  router.push({ name: 'inicio', params: { slug: marca.slug } })
}
</script>

<template>
  <main v-if="pedido" class="relative min-h-dvh">
    <!-- Aviso de reconexión (RN-47) -->
    <Transition name="pantalla">
      <div
        v-if="reconectando"
        class="pt-seguro fixed inset-x-0 top-0 z-30 mx-auto flex max-w-md items-center justify-center gap-2 bg-tinta px-4 pb-2 text-sm font-medium text-white"
        role="status"
      >
        <Icon icon="tabler:wifi-off" class="size-4" /> Reconectando…
      </div>
    </Transition>

    <!-- Terminó mientras se veía: entregado o cancelado -->
    <section
      v-if="terminado"
      class="pt-seguro flex min-h-dvh flex-col items-center justify-center px-6 pb-8 text-center"
    >
      <span
        class="flex size-24 items-center justify-center rounded-full"
        :class="entregado ? 'bg-green-100' : 'bg-red-50'"
      >
        <Icon
          :icon="entregado ? 'tabler:circle-check' : 'tabler:x'"
          class="size-14"
          :class="entregado ? 'text-exito' : 'text-peligro'"
        />
      </span>
      <h1 class="mt-6 font-titulo text-3xl font-extrabold" aria-live="polite">
        {{ entregado ? '¡Entregado!' : mensajeDe(pedido.estado, pedido.sinRepartidor) }}
      </h1>
      <p class="mt-2 text-suave">
        <template v-if="entregado">Gracias por pedir con {{ marca.nombre }}.</template>
        <template v-else-if="pedido.sinRepartidor">
          Ningún repartidor estuvo disponible. Puedes intentarlo de nuevo en unos minutos.
        </template>
        <template v-else>Tu pedido {{ pedido.numero }} quedó cancelado.</template>
      </p>
      <div class="mt-8 w-full space-y-2">
        <BotonPrincipal @click="inicio">Volver al inicio</BotonPrincipal>
        <BotonPrincipal
          variante="texto"
          @click="router.push({ name: 'detalle', params: { slug: marca.slug, id: pedido.id } })"
        >
          Ver detalle
        </BotonPrincipal>
      </div>
    </section>

    <!-- 6.4 Buscando repartidor -->
    <section
      v-else-if="buscando"
      class="pt-seguro flex min-h-dvh flex-col bg-linear-to-b from-morado-800 to-morado-600 px-5 pb-8 text-white"
    >
      <button
        type="button"
        class="-ml-2 mt-1 flex size-11 items-center justify-center rounded-full active:bg-white/10"
        aria-label="Ir al inicio"
        @click="inicio"
      >
        <Icon icon="tabler:arrow-left" class="size-6" />
      </button>

      <div class="flex flex-1 flex-col items-center justify-center text-center">
        <template v-if="pedido.programadoPara">
          <span class="flex size-28 items-center justify-center rounded-full bg-white/10">
            <Icon icon="tabler:calendar" class="size-14 text-amarillo-500" />
          </span>
          <h1 class="mt-8 font-titulo text-3xl font-extrabold">Pedido programado</h1>
          <p class="mt-2 text-morado-100">
            {{ fechaCorta(pedido.programadoPara) }}. Buscaremos a tu repartidor 15 minutos antes.
          </p>
        </template>
        <template v-else>
          <div class="relative flex size-40 items-center justify-center" aria-hidden="true">
            <span class="onda-busqueda absolute inset-0 rounded-full bg-amarillo-500/40" />
            <span
              class="onda-busqueda absolute inset-0 rounded-full bg-amarillo-500/30 [animation-delay:0.7s]"
            />
            <span
              class="onda-busqueda absolute inset-0 rounded-full bg-amarillo-500/20 [animation-delay:1.4s]"
            />
            <span
              class="relative flex size-24 items-center justify-center rounded-full bg-white shadow-flotante"
            >
              <Icon icon="tabler:motorbike" class="moto-avanza size-12 text-morado-700" />
            </span>
          </div>
          <h1 class="mt-10 font-titulo text-3xl font-extrabold" aria-live="polite">
            Estamos buscando un repartidor para ti
          </h1>
          <p class="mt-2 text-morado-100">Te avisamos en cuanto alguien lo acepte.</p>
        </template>

        <div class="mt-8 w-full rounded-3xl bg-white/10 p-4 text-left text-sm">
          <p class="text-morado-200">Pedido {{ pedido.numero }} · {{ pesos(total) }}</p>
          <p class="mt-1 truncate">{{ pedido.recogida.direccion }}</p>
          <p class="truncate text-morado-200">→ {{ pedido.entrega.direccion }}</p>
        </div>
      </div>

      <BotonPrincipal
        v-if="clientePuedeCancelar(pedido.estado)"
        variante="claro"
        @click="confirmarCancelacion = true"
      >
        Cancelar pedido
      </BotonPrincipal>
    </section>

    <!-- 6.5 Seguimiento -->
    <section v-else class="flex min-h-dvh flex-col">
      <div class="relative h-[58dvh] shrink-0">
        <MapaCliente :id="ID" :centro="pedido.posicion ?? pedido.recogida" @listo="alListo" />
        <button
          type="button"
          class="absolute top-[max(env(safe-area-inset-top),0.75rem)] left-3 flex size-12 items-center justify-center rounded-full bg-white shadow-tarjeta"
          aria-label="Ir al inicio"
          @click="inicio"
        >
          <Icon icon="tabler:arrow-left" class="size-6" />
        </button>
      </div>

      <div class="relative -mt-8 flex-1 rounded-t-[2rem] bg-white px-5 pt-5 pb-8 shadow-flotante">
        <div class="mx-auto mb-4 h-1.5 w-10 rounded-full bg-linea" aria-hidden="true" />

        <h1 class="font-titulo text-2xl font-extrabold" aria-live="polite">
          {{ mensajeDe(pedido.estado, pedido.sinRepartidor) }}
        </h1>

        <div class="mt-5">
          <LineaProgreso :estado="pedido.estado" />
        </div>

        <div v-if="enCurso && pedido.repartidor" class="mt-6 rounded-3xl bg-fondo p-4">
          <TarjetaRepartidor
            :repartidor="pedido.repartidor"
            :minutos="minutos"
            :numero="pedido.numero"
          />
        </div>

        <div v-if="!enCurso" class="mt-6 rounded-3xl bg-amarillo-100 p-4 text-sm text-tinta">
          <p>¿Dudas con tu envío? Llama a {{ marca.nombre }}.</p>
        </div>

        <dl class="mt-5 space-y-1 text-sm">
          <div class="flex justify-between">
            <dt class="text-suave">Total en efectivo</dt>
            <dd class="font-semibold">{{ pesos(total) }}</dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-suave">Entrega</dt>
            <dd class="ml-6 truncate text-right">{{ pedido.entrega.direccion }}</dd>
          </div>
        </dl>

        <p v-if="enCurso" class="mt-6 text-center text-sm text-suave">
          ¿Necesitas cancelar?
          <a
            v-if="marca.marca?.telefonoContacto"
            :href="`tel:${marca.marca.telefonoContacto}`"
            class="font-semibold text-morado-700 underline underline-offset-2"
          >
            Llama a la flotilla ({{ formatoTelefono(marca.marca.telefonoContacto) }})
          </a>
        </p>
      </div>
    </section>

    <HojaInferior v-model="confirmarCancelacion" titulo="¿Cancelar tu pedido?">
      <p class="-mt-1 text-suave">Todavía nadie lo ha aceptado, así que no tiene costo.</p>
      <div class="mt-5 space-y-2">
        <BotonPrincipal variante="peligro" :cargando="cancelando" @click="cancelar">
          Sí, cancelar
        </BotonPrincipal>
        <BotonPrincipal variante="texto" @click="confirmarCancelacion = false">
          No, seguir esperando
        </BotonPrincipal>
      </div>
    </HojaInferior>
  </main>

  <main v-else class="flex min-h-dvh items-center justify-center" aria-busy="true">
    <Icon icon="tabler:refresh" class="size-8 animate-spin text-morado-500" />
  </main>
</template>
