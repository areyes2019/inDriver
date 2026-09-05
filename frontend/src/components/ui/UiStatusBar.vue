<script setup lang="ts">
import UiToggle from './UiToggle.vue'
import UiBadge from './UiBadge.vue'

const modelValue = defineModel<boolean>({ default: false })

withDefaults(
  defineProps<{
    label: string
    badges?: Array<{ text: string | number; color?: 'orange' | 'blue' | 'green' | 'red' | 'gray' }>
  }>(),
  {
    badges: () => [],
  },
)
</script>

<template>
  <div
    class="flex flex-wrap items-center justify-between gap-3 border-b border-default bg-neutral-primary px-4 py-3 md:px-8"
  >
    <div class="flex items-center gap-3">
      <span class="text-sm font-semibold text-heading">{{ label }}</span>
      <UiToggle v-model="modelValue" />
    </div>

    <div class="flex items-center gap-2">
      <UiBadge
        v-for="(badge, index) in badges"
        :key="index"
        :text="badge.text"
        :color="badge.color"
      />
      <slot name="extra" />
    </div>
  </div>
</template>
