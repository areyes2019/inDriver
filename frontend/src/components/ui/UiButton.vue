<script setup lang="ts">
withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary'
    type?: 'button' | 'submit'
    disabled?: boolean
    fullWidth?: boolean
  }>(),
  {
    variant: 'primary',
    type: 'button',
    disabled: false,
    fullWidth: true,
  },
)

const variantClasses: Record<string, string> = {
  primary: 'bg-accent text-white hover:bg-heading',
  secondary: 'bg-white text-heading border border-gray-300 hover:bg-slate-50',
}
</script>

<template>
  <button
    :type="type"
    :disabled="disabled"
    class="rounded-lg px-4 py-2.5 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60"
    :class="[variantClasses[variant], fullWidth ? 'w-full' : 'w-auto']"
  >
    <slot />
  </button>
</template>
