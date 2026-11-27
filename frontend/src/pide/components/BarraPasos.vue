<script setup lang="ts">
const props = defineProps<{ paso: number }>()
const nombres = ['Recogida', 'Entrega', 'Detalles', 'Confirmar']
</script>

<template>
  <ol class="flex gap-1.5 px-5" :aria-label="`Paso ${props.paso} de 4`">
    <li v-for="(nombre, i) in nombres" :key="nombre" class="flex-1">
      <div
        class="h-1.5 rounded-full transition-colors duration-300"
        :class="i < props.paso ? 'bg-morado-600' : 'bg-linea'"
      />
      <p
        class="mt-1.5 text-center text-xs"
        :class="i + 1 === props.paso ? 'font-semibold text-morado-700' : 'text-suave'"
      >
        {{ nombre }}
      </p>
    </li>
  </ol>
</template>
