<script setup lang="ts">
/**
 * Logo de la flotilla, o el panda de MotoPanda si no ha subido uno (D16, RN-62). Es un dibujo
 * provisional para el prototipo: el definitivo es el archivo oficial de la marca.
 */
withDefaults(defineProps<{ url: string | null; nombre: string; tamano?: number }>(), {
  tamano: 56,
})
</script>

<template>
  <img
    v-if="url"
    :src="url"
    :alt="nombre"
    :width="tamano"
    :height="tamano"
    class="rounded-full bg-white object-cover"
  />
  <svg
    v-else
    :width="tamano"
    :height="tamano"
    viewBox="0 0 64 64"
    role="img"
    :aria-label="nombre"
    class="shrink-0"
  >
    <circle cx="32" cy="32" r="31" fill="#fff" stroke="#2e1065" stroke-width="2" />
    <circle cx="14" cy="16" r="8" fill="#1b1530" />
    <circle cx="50" cy="16" r="8" fill="#1b1530" />
    <path d="M8 30a24 24 0 0 1 48 0v2H8z" fill="#6d28d9" />
    <rect
      x="13"
      y="22"
      width="38"
      height="9"
      rx="4.5"
      fill="#facc15"
      stroke="#2e1065"
      stroke-width="1.5"
    />
    <ellipse cx="32" cy="42" rx="18" ry="15" fill="#fff" />
    <ellipse cx="24" cy="40" rx="5" ry="6" fill="#1b1530" transform="rotate(-20 24 40)" />
    <ellipse cx="40" cy="40" rx="5" ry="6" fill="#1b1530" transform="rotate(20 40 40)" />
    <circle cx="25" cy="39" r="1.8" fill="#fff" />
    <circle cx="39" cy="39" r="1.8" fill="#fff" />
    <ellipse cx="32" cy="48" rx="3" ry="2" fill="#1b1530" />
    <path
      d="M29 51.5q3 2.5 6 0"
      stroke="#1b1530"
      stroke-width="1.5"
      fill="none"
      stroke-linecap="round"
    />
  </svg>
</template>
