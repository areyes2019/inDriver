<script setup lang="ts">
/**
 * Marco de teléfono para las maquetas de la página pública. El contenido de la pantalla va en el
 * slot; la barra de estado es decorativa.
 */
defineProps<{ etiqueta: string; oscuro?: boolean }>()
</script>

<template>
  <div
    class="relative mx-auto w-[15.5rem] rounded-[2.4rem] border-[7px] border-tinta bg-tinta shadow-2xl shadow-panda-950/30"
    role="img"
    :aria-label="etiqueta"
  >
    <div
      class="absolute top-1.5 left-1/2 z-10 h-4 w-20 -translate-x-1/2 rounded-full bg-tinta"
      aria-hidden="true"
    />
    <div
      class="relative h-[30rem] overflow-hidden rounded-[1.9rem]"
      :class="oscuro ? 'bg-[#0b141a]' : 'bg-[#f7f6fb]'"
    >
      <div
        class="flex justify-between px-5 pt-2 text-[10px] font-semibold"
        :class="oscuro ? 'text-white/80' : 'text-tinta/70'"
        aria-hidden="true"
      >
        <span>9:41</span>
        <span>5G ▮▮▮</span>
      </div>
      <slot />
    </div>
  </div>
</template>
