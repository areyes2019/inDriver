<script setup lang="ts">
withDefaults(
  defineProps<{
    text: string | number
    color?: 'green' | 'yellow' | 'blue'
  }>(),
  {
    color: 'green',
  },
)

const colorClasses: Record<string, string> = {
  green: 'bg-emerald-500 text-white',
  yellow: 'bg-brand-yellow text-brand-dark',
  blue: 'bg-brand-blue text-white',
}
</script>

<template>
  <span
    class="inline-flex min-w-[1.5rem] items-center justify-center rounded-full px-2 py-0.5 text-xs font-semibold"
    :class="colorClasses[color]"
  >
    {{ text }}
  </span>
</template>
