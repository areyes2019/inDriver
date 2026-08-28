<script setup lang="ts">
withDefaults(
  defineProps<{
    text: string | number
    color?: 'orange' | 'blue' | 'green' | 'red' | 'gray'
  }>(),
  {
    color: 'gray',
  },
)

const colorClasses: Record<string, string> = {
  orange: 'bg-orange-100 text-orange-700',
  blue: 'bg-blue-100 text-blue-700',
  green: 'bg-green-100 text-green-700',
  red: 'bg-red-100 text-red-700',
  gray: 'bg-slate-100 text-slate-700',
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
