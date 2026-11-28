<script setup lang="ts">
import { Icon } from '@iconify/vue'
import MaquetaTelefono from './MaquetaTelefono.vue'

/** Maqueta de la app del repartidor: le llega una oferta de viaje con su pago y la acepta. */
</script>

<template>
  <MaquetaTelefono etiqueta="Maqueta de la app del repartidor recibiendo una oferta de viaje">
    <div class="flex items-center justify-between px-4 pt-3">
      <div>
        <p class="text-[10px] text-tinta/50">Hola, Carlos</p>
        <p class="text-xs font-bold text-tinta">Estás en servicio</p>
      </div>
      <span
        class="flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[9px] font-bold text-emerald-700"
      >
        <span class="size-1.5 rounded-full bg-emerald-500" /> En línea
      </span>
    </div>

    <div class="mx-4 mt-3 grid grid-cols-2 gap-2">
      <div class="rounded-xl bg-white p-2.5 shadow-sm">
        <p class="text-[9px] text-tinta/50">Viajes hoy</p>
        <p class="font-heading text-lg font-bold text-tinta">12</p>
      </div>
      <div class="rounded-xl bg-white p-2.5 shadow-sm">
        <p class="text-[9px] text-tinta/50">Saldo prepagado</p>
        <p class="font-heading text-lg font-bold text-tinta">38</p>
      </div>
    </div>

    <div class="oferta mx-4 mt-4 rounded-2xl border-2 border-panda-500 bg-white p-3 shadow-lg">
      <div class="flex items-center justify-between">
        <p class="text-[10px] font-bold tracking-wide text-panda-700 uppercase">Nuevo viaje</p>
        <p class="text-[10px] font-semibold text-tinta/50">38 s</p>
      </div>
      <p class="mt-1 font-heading text-2xl font-extrabold text-tinta">$45.00</p>
      <div class="mt-2 space-y-1.5 text-[10px] text-tinta/70">
        <p class="flex items-start gap-1.5">
          <Icon icon="tabler:map-pin" class="mt-px size-3.5 shrink-0 text-panda-600" />
          Av. Tecnológico 100, Celaya
        </p>
        <p class="flex items-start gap-1.5">
          <Icon icon="tabler:route" class="mt-px size-3.5 shrink-0 text-panda-600" />
          Col. Alameda · 4.2 km
        </p>
      </div>
      <div class="mt-3 grid grid-cols-2 gap-2">
        <span class="rounded-xl bg-gray-100 py-2 text-center text-[11px] font-bold text-tinta/60"
          >Pasar</span
        >
        <span class="rounded-xl bg-panda-700 py-2 text-center text-[11px] font-bold text-white"
          >Aceptar</span
        >
      </div>
    </div>

    <p class="mt-4 px-4 text-center text-[9px] text-tinta/40">
      La ruta abre en Google Maps o Waze con un toque
    </p>
  </MaquetaTelefono>
</template>

<style scoped>
.oferta {
  animation: latido 2.4s ease-in-out infinite;
}

@keyframes latido {
  0%,
  100% {
    box-shadow: 0 0 0 0 rgb(124 58 237 / 0.35);
  }
  50% {
    box-shadow: 0 0 0 10px rgb(124 58 237 / 0);
  }
}

@media (prefers-reduced-motion: reduce) {
  .oferta {
    animation: none;
  }
}
</style>
