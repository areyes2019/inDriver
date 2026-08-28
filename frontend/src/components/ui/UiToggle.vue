<script setup lang="ts">
const modelValue = defineModel<boolean>({ default: false })

withDefaults(
  defineProps<{
    disabled?: boolean
  }>(),
  {
    disabled: false,
  },
)
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="modelValue"
    :disabled="disabled"
    class="inline-flex h-7 w-12 shrink-0 items-center rounded-full p-1 transition-colors disabled:cursor-not-allowed disabled:opacity-40"
    :class="modelValue ? 'bg-brand-dark' : 'bg-black/15'"
    @click="!disabled && (modelValue = !modelValue)"
  >
    <span
      class="h-5 w-5 rounded-full bg-white shadow transition-transform"
      :class="modelValue ? 'translate-x-5' : 'translate-x-0'"
    />
  </button>
</template>
