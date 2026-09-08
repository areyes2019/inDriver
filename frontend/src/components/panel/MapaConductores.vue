<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import mapService from '@/services/maps/MapService'
import type { LatLngLike } from '@/services/maps/types'
import { useTenantAuthStore } from '@/stores/tenantAuth'
import { usePanelStore, type ConductorActivo } from '@/stores/panel'

/**
 * Mapa del Panel (specs tenant/009, tenant/021, tenant/026, tenant/027).
 *
 * Desde la spec tenant/027 no pide `/conductores/activos` ni escucha el canal: lee el mismo
 * `usePanelStore` que la lista de flotilla. Antes tenía su propia copia de los conductores y la
 * recargaba con cinco eventos, en paralelo con `ConductoresActivos` haciendo lo mismo — dos
 * peticiones idénticas por evento, contra el limitador que las dos compartían.
 */

const CONTAINER_ID = 'mapa-conductores'
const tenantAuth = useTenantAuthStore()
const panel = usePanelStore()

/** RN-14 de la spec tenant/026: cada cuánto se repide la ruta del tramo H1 mientras el conductor avanza. */
const REDIBUJO_METROS = 50
const REDIBUJO_MS = 15_000

const mapaListo = ref(false)

/** Ids con marcador puesto ahora mismo, para saber cuándo hay que rehacer el conjunto. */
let idsConMarcador = new Set<number>()
/** Desde dónde y cuándo se trazó la línea de cada conductor, para no repedirla en cada punto. */
const ultimoTrazo = new Map<number, { hito: 'H1' | 'H2'; desde: LatLngLike; en: number }>()
/** Última línea pedida por conductor, para distinguir "cambió el hito" de "solo se movió". */
const ultimoSeguimiento = new Map<number, string>()

function rutaId(idConductor: number): string {
  return `ruta-${idConductor}`
}

function posicionDe(conductor: ConductorActivo): LatLngLike | null {
  if (conductor.latitud === null || conductor.longitud === null) return null
  return { lat: conductor.latitud, lng: conductor.longitud }
}

/** Haversine, en metros. Solo se usa para decidir si vale la pena repedir la ruta. */
function metrosEntre(a: LatLngLike, b: LatLngLike): number {
  const radio = 6371000
  const dLat = ((b.lat - a.lat) * Math.PI) / 180
  const dLng = ((b.lng - a.lng) * Math.PI) / 180
  const lat1 = (a.lat * Math.PI) / 180
  const lat2 = (b.lat * Math.PI) / 180
  const h = Math.sin(dLat / 2) ** 2 + Math.sin(dLng / 2) ** 2 * Math.cos(lat1) * Math.cos(lat2)
  return 2 * radio * Math.asin(Math.sqrt(h))
}

/**
 * Una línea por conductor (spec tenant/026, RN-16): `drawRoute` reusa el mismo `routeId`, así que
 * entrar a H2 borra la de H1 sin que haya que limpiarla aparte. Sin envío activo no hay línea (RN-21).
 */
function dibujarTramo(conductor: ConductorActivo) {
  const seguimiento = conductor.pedido_asignado?.seguimiento ?? null

  if (!seguimiento) {
    mapService.clearRoute(CONTAINER_ID, rutaId(conductor.id_conductor))
    ultimoTrazo.delete(conductor.id_conductor)
    return
  }

  const origen = seguimiento.origen ?? posicionDe(conductor)
  if (!origen) return

  mapService.drawRoute(
    CONTAINER_ID,
    rutaId(conductor.id_conductor),
    [origen, seguimiento.destino],
    {
      color: seguimiento.color,
      estilo: seguimiento.estilo,
      preserveViewport: true,
    },
  )

  ultimoTrazo.set(conductor.id_conductor, {
    hito: seguimiento.hito,
    desde: origen,
    en: Date.now(),
  })
}

/**
 * Redibuja solo cuando hace falta. Un cambio de hito o de destino manda siempre; si la línea es la
 * misma, únicamente el tramo H1 sigue a la moto, y con la cadencia de la spec tenant/026 (RN-14):
 * repedirle una ruta a Directions en cada punto sería absurdo cuando el dibujo casi no cambia.
 */
function dibujarTramoSiCambio(conductor: ConductorActivo, forzar: boolean) {
  const seguimiento = conductor.pedido_asignado?.seguimiento ?? null
  const firma = JSON.stringify(seguimiento)
  const cambio = ultimoSeguimiento.get(conductor.id_conductor) !== firma
  ultimoSeguimiento.set(conductor.id_conductor, firma)

  if (!seguimiento) {
    if (cambio || forzar) dibujarTramo(conductor)
    return
  }

  if (cambio || forzar) {
    dibujarTramo(conductor)
    return
  }

  // El tramo H2 arranca en el punto de recogida, que no se mueve (RN-15): no hay nada que
  // recalcular. Solo el H1, que sale de donde está el conductor ahora mismo.
  if (seguimiento.hito !== 'H1') return

  const ahora = posicionDe(conductor)
  if (!ahora) return

  const previo = ultimoTrazo.get(conductor.id_conductor)
  if (
    previo?.hito === 'H1' &&
    metrosEntre(previo.desde, ahora) < REDIBUJO_METROS &&
    Date.now() - previo.en < REDIBUJO_MS
  ) {
    return
  }

  dibujarTramo(conductor)
}

/**
 * Lleva el mapa al estado que dice el store. Mientras el conjunto de conductores visibles no cambie
 * —el caso normal, porque lo que llega todo el rato son posiciones— solo se mueven los marcadores:
 * `MapService` no expone quitar uno suelto, así que rehacer el conjunto se reserva para cuando
 * alguien entra o sale.
 */
function sincronizarMapa(lista: ConductorActivo[]) {
  if (!mapaListo.value) return

  const visibles = lista.filter((conductor) => posicionDe(conductor) !== null)
  const ids = new Set(visibles.map((conductor) => conductor.id_conductor))
  const mismoConjunto =
    ids.size === idsConMarcador.size && [...ids].every((id) => idsConMarcador.has(id))

  if (!mismoConjunto) {
    mapService.clearMarkers(CONTAINER_ID)
    mapService.clearRoutes(CONTAINER_ID)
    ultimoTrazo.clear()
    ultimoSeguimiento.clear()

    for (const conductor of visibles) {
      mapService.addMarker(
        CONTAINER_ID,
        String(conductor.id_conductor),
        posicionDe(conductor) as LatLngLike,
        { title: conductor.nombre, color: conductor.color },
      )
      dibujarTramoSiCambio(conductor, true)
    }

    idsConMarcador = ids
    return
  }

  for (const conductor of visibles) {
    mapService.updateMarker(
      CONTAINER_ID,
      String(conductor.id_conductor),
      posicionDe(conductor) as LatLngLike,
    )
    dibujarTramoSiCambio(conductor, false)
  }
}

watch(() => panel.conductoresOrdenados, sincronizarMapa)

onMounted(async () => {
  if (!mapService.hasApiKey()) return

  await mapService.initialize(CONTAINER_ID, { zoom: 12 })

  const ciudades = tenantAuth.usuario?.ciudades_tenant ?? []
  if (ciudades.length > 0) {
    mapService.fitToPositions(
      CONTAINER_ID,
      ciudades.map((ciudad) => ({ lat: ciudad.lat, lng: ciudad.lng, bounds: ciudad.bounds })),
    )
  }

  mapaListo.value = true
  // La carga inicial la hace el store; si ya llegó mientras se inicializaba Google, esto la pinta.
  sincronizarMapa(panel.conductoresOrdenados)
})

onBeforeUnmount(() => {
  mapService.destroy(CONTAINER_ID)
})

/**
 * spec tenant/023: el mapa es el fondo de todo el Panel y el panel de flotilla se colapsa encima de
 * él. Google no se entera solo de que su contenedor cambió de ancho, así que `PanelView` llama a
 * esto al terminar la animación.
 */
defineExpose({
  redimensionar: () => mapService.resize(CONTAINER_ID),
})
</script>

<template>
  <div
    v-if="mapService.hasApiKey()"
    :id="CONTAINER_ID"
    class="h-full w-full"
    data-testid="mapa-conductores"
  />
  <p v-else class="p-5 text-sm text-black/50">
    Configura la clave de Google Maps para ver el mapa.
  </p>
</template>
