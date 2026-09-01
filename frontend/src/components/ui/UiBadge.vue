<script setup lang="ts">
withDefaults(
  defineProps<{
    text: string | number
    color?: 'orange' | 'blue' | 'green' | 'red' | 'gray' | 'purple'
  }>(),
  {
    color: 'gray',
  },
)

const colorClasses: Record<string, string> = {
  orange: 'bg-warning-bg text-warning-text',
  blue: 'bg-info-bg text-info-text',
  green: 'bg-success-bg text-success-text',
  red: 'bg-red-100 text-red-700',
  gray: 'bg-slate-100 text-slate-700',
  purple: 'bg-purple-bg text-purple-text',
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
