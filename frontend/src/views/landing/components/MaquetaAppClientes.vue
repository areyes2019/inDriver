<script setup lang="ts">
import { Icon } from '@iconify/vue'
import MaquetaTelefono from './MaquetaTelefono.vue'

/** Maqueta de la app de clientes con la marca de una flotilla inventada ("Tu Flotilla"). */
</script>

<template>
  <MaquetaTelefono etiqueta="Maqueta de la app de clientes con el logo y el banner de tu flotilla">
    <div class="flex items-center gap-2 px-4 pt-3">
      <span
        class="flex size-9 items-center justify-center rounded-full bg-sol-500 font-heading text-sm font-extrabold text-panda-950"
        >TF</span
      >
      <div>
        <p class="text-xs font-bold text-tinta">Tu Flotilla</p>
        <p class="text-[10px] text-tinta/50">Hola, Mariana 👋</p>
      </div>
      <Icon icon="tabler:bell" class="ml-auto size-5 text-tinta/60" />
    </div>

    <div
      class="relative mx-4 mt-3 h-28 overflow-hidden rounded-2xl bg-gradient-to-br from-panda-700 to-panda-500 p-3 text-white"
    >
      <p class="font-heading text-base leading-tight font-bold">Envíos en<br />toda la ciudad</p>
      <p class="mt-1 text-[10px] text-white/80">Hoy, envío desde $35</p>
      <Icon
        icon="tabler:motorbike"
        class="absolute -right-1 -bottom-3 size-20 text-sol-400 opacity-90"
      />
    </div>

    <div class="mt-4 grid grid-cols-2 gap-2.5 px-4">
      <div class="rounded-2xl bg-white p-3 shadow-sm">
        <Icon icon="tabler:package-export" class="size-7 text-panda-700" />
        <p class="mt-2 text-xs font-bold text-tinta">Enviar</p>
        <p class="text-[9px] text-tinta/50">Mandamos tu paquete</p>
      </div>
      <div class="rounded-2xl bg-white p-3 shadow-sm">
        <Icon icon="tabler:package-import" class="size-7 text-panda-700" />
        <p class="mt-2 text-xs font-bold text-tinta">Recibir</p>
        <p class="text-[9px] text-tinta/50">Te lo traemos</p>
      </div>
    </div>

    <div class="mx-4 mt-3 rounded-2xl bg-white p-3 shadow-sm">
      <div class="flex items-center gap-2">
        <span class="relative flex size-2.5">
          <span
            class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"
          />
          <span class="relative inline-flex size-2.5 rounded-full bg-emerald-500" />
        </span>
        <p class="text-[11px] font-bold text-tinta">Tu repartidor va en camino</p>
      </div>
      <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-panda-100">
        <div class="barra-avance h-full w-2/3 rounded-full bg-panda-600" />
      </div>
      <p class="mt-1.5 text-[9px] text-tinta/50">Llega en ~8 min · Carlos · Italika 150</p>
    </div>

    <div
      class="absolute inset-x-0 bottom-0 flex justify-around border-t border-gray-100 bg-white py-2.5 text-tinta/40"
      aria-hidden="true"
    >
      <Icon icon="tabler:home" class="size-5 text-panda-700" />
      <Icon icon="tabler:list-details" class="size-5" />
      <Icon icon="tabler:user-circle" class="size-5" />
    </div>
  </MaquetaTelefono>
</template>

<style scoped>
.barra-avance {
  animation: avanzar 6s ease-in-out infinite alternate;
}

@keyframes avanzar {
  from {
    width: 35%;
  }
  to {
    width: 85%;
  }
}

@media (prefers-reduced-motion: reduce) {
  .barra-avance {
    animation: none;
  }
}
</style>
