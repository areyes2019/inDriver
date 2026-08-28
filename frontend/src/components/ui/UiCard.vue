<script setup lang="ts">
withDefaults(
  defineProps<{
    title?: string
  }>(),
  {
    title: undefined,
  },
)
</script>

<template>
  <section class="rounded-2xl bg-white p-5 shadow-sm shadow-black/5">
    <header v-if="title || $slots.header" class="mb-4 flex items-center justify-between gap-2">
      <slot name="header">
        <h2 class="text-base font-semibold text-brand-dark">{{ title }}</h2>
      </slot>
    </header>
    <slot />
  </section>
</template>
