<script setup lang="ts">
import { Icon } from '@iconify/vue'

withDefaults(
  defineProps<{
    icon: string
    iconBg?: string
    label: string
    value: string | number
  }>(),
  {
    iconBg: 'bg-accent-soft',
  },
)
</script>

<template>
  <div
    class="flex min-w-[200px] items-center gap-4 rounded-lg border border-default bg-neutral-primary p-4 shadow-sm"
  >
    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md" :class="iconBg">
      <Icon :icon="icon" width="24" height="24" aria-hidden="true" />
    </span>
    <div>
      <p class="text-xs font-semibold tracking-wide text-body uppercase">{{ label }}</p>
      <h3 class="text-xl font-extrabold text-heading">{{ value }}</h3>
    </div>
  </div>
</template>
