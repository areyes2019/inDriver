<script setup lang="ts">
defineProps<{
  text: string
}>()
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full bg-warning-bg px-3 py-1 text-xs font-bold text-warning-text"
  >
    <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-amber-500" />
    {{ text }}
  </span>
</template>
