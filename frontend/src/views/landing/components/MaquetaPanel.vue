<script setup lang="ts">
import { Icon } from '@iconify/vue'

/**
 * Maqueta del Panel de la flotilla para la página pública: mapa con la zona de cobertura, motos
 * moviéndose por su ruta y la lista de viajes en turno. Es un dibujo, no una captura: los datos son
 * inventados y no sale nada del sistema real.
 */
const viajes = [
  { numero: 'PED-000184', estado: 'En camino', color: 'bg-emerald-500', repartidor: 'Carlos R.' },
  { numero: 'PED-000185', estado: 'Recogiendo', color: 'bg-sol-500', repartidor: 'Ana M.' },
  { numero: 'PED-000186', estado: 'Buscando repartidor', color: 'bg-panda-500', repartidor: '—' },
]

// Rutas de las motos sobre el mapa (coordenadas del viewBox 0 0 400 260).
const rutas = [
  {
    inicio: [60, 200],
    d: 'M60 200 L120 200 L120 120 L230 120 L230 60 L330 60',
    duracion: '9s',
    color: '#5b21b6',
  },
  {
    inicio: [340, 210],
    d: 'M340 210 L260 210 L260 150 L150 150 L150 80 L90 80',
    duracion: '11s',
    color: '#16a34a',
  },
  {
    inicio: [200, 240],
    d: 'M200 240 L200 180 L300 180 L300 100 L360 100',
    duracion: '8s',
    color: '#ca8a04',
  },
]

// Con la reducción de movimiento activada, las motos se quedan quietas al inicio de su ruta.
const animar =
  typeof window === 'undefined' || !window.matchMedia('(prefers-reduced-motion: reduce)').matches
</script>

<template>
  <div
    class="overflow-hidden rounded-2xl border border-white/20 bg-white shadow-2xl shadow-panda-950/30"
    role="img"
    aria-label="Maqueta del Panel de Motopanda: mapa con repartidores en tiempo real y lista de viajes en turno"
  >
    <!-- Barra de la ventana -->
    <div class="flex items-center gap-2 border-b border-gray-100 bg-gray-50 px-4 py-2.5">
      <span class="size-2.5 rounded-full bg-red-400" />
      <span class="size-2.5 rounded-full bg-amber-400" />
      <span class="size-2.5 rounded-full bg-green-400" />
      <span class="ml-3 rounded-md bg-white px-3 py-0.5 text-[10px] text-gray-400">
        motopanda.mx/tu-flotilla/panel
      </span>
    </div>

    <div class="grid grid-cols-[2.25rem_1fr] sm:grid-cols-[2.5rem_1fr_11rem]">
      <!-- Menú lateral -->
      <div class="flex flex-col items-center gap-3 bg-[#192a56] py-3 text-white/70">
        <Icon icon="tabler:plus" class="size-4 rounded bg-sol-500 p-0.5 text-panda-950" />
        <Icon icon="tabler:map" class="size-4 text-white" />
        <Icon icon="tabler:messages" class="size-4" />
        <Icon icon="tabler:users" class="size-4" />
        <Icon icon="tabler:steering-wheel" class="size-4" />
      </div>

      <!-- Mapa -->
      <div class="relative aspect-[400/260] bg-[#eef2e6]">
        <svg viewBox="0 0 400 260" class="absolute inset-0 size-full" aria-hidden="true">
          <defs>
            <pattern id="cuadras" width="40" height="40" patternUnits="userSpaceOnUse">
              <rect width="40" height="40" fill="#eef2e6" />
              <path d="M0 0H40M0 0V40" stroke="#ffffff" stroke-width="5" />
            </pattern>
          </defs>
          <rect width="400" height="260" fill="url(#cuadras)" />
          <path d="M0 130 C120 110 260 160 400 120" stroke="#fde68a" stroke-width="9" fill="none" />
          <path d="M180 0 C170 90 220 170 200 260" stroke="#fde68a" stroke-width="7" fill="none" />
          <!-- Zona de cobertura -->
          <polygon
            points="70,40 250,22 360,70 372,190 260,238 90,226 42,140"
            fill="#7c3aed"
            fill-opacity="0.09"
            stroke="#5b21b6"
            stroke-width="2"
            stroke-dasharray="6 5"
          />
          <!-- Rutas y motos -->
          <g v-for="(ruta, i) in rutas" :key="i">
            <path
              :d="ruta.d"
              :stroke="ruta.color"
              stroke-width="3"
              fill="none"
              stroke-linecap="round"
              stroke-opacity="0.55"
            />
            <g :transform="animar ? undefined : `translate(${ruta.inicio[0]} ${ruta.inicio[1]})`">
              <circle r="9" :fill="ruta.color" stroke="#fff" stroke-width="2.5" />
              <path d="M-4 1 L0 -4 L4 1 L0 -1 Z" fill="#fff" />
              <animateMotion
                v-if="animar"
                :dur="ruta.duracion"
                repeatCount="indefinite"
                :path="ruta.d"
                rotate="0"
              />
            </g>
          </g>
          <!-- Destinos -->
          <g
            v-for="(punto, i) in [
              [330, 60],
              [90, 80],
              [360, 100],
            ]"
            :key="`d${i}`"
          >
            <circle
              :cx="punto[0]"
              :cy="punto[1]"
              r="5"
              fill="#1b1530"
              stroke="#fff"
              stroke-width="2"
            />
          </g>
        </svg>
        <div
          class="absolute top-2 left-1/2 -translate-x-1/2 rounded-full bg-white/95 px-3 py-1 text-[10px] font-semibold text-tinta shadow"
        >
          Saldo: <span class="text-panda-700">248 viajes</span>
        </div>
      </div>

      <!-- Viajes en turno -->
      <div class="hidden flex-col gap-2 border-l border-gray-100 p-2.5 sm:flex">
        <p class="text-[10px] font-bold tracking-wide text-gray-400 uppercase">Viajes en turno</p>
        <div
          v-for="viaje in viajes"
          :key="viaje.numero"
          class="rounded-lg border border-gray-100 p-2 shadow-sm"
        >
          <p class="text-[10px] font-bold text-tinta">{{ viaje.numero }}</p>
          <p class="mt-1 flex items-center gap-1 text-[9px] text-gray-500">
            <span class="size-1.5 rounded-full" :class="viaje.color" />
            {{ viaje.estado }}
          </p>
          <p class="mt-0.5 text-[9px] text-gray-400">{{ viaje.repartidor }}</p>
        </div>
      </div>
    </div>
  </div>
</template>
