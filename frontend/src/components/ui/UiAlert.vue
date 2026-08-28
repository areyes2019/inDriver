<script setup lang="ts">
withDefaults(
  defineProps<{
    variant?: 'info' | 'success' | 'error' | 'warning'
  }>(),
  {
    variant: 'info',
  },
)

const variantClasses: Record<string, string> = {
  info: 'bg-blue-50 text-blue-700 border-blue-200',
  success: 'bg-green-50 text-green-700 border-green-200',
  error: 'bg-red-50 text-red-700 border-red-200',
  warning: 'bg-orange-50 text-orange-700 border-orange-200',
}
</script>

<template>
  <p role="alert" class="rounded-lg border px-3 py-2 text-sm" :class="variantClasses[variant]">
    <slot />
  </p>
</template>
