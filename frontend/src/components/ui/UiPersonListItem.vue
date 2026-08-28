<script setup lang="ts">
import UiBadge from './UiBadge.vue'

withDefaults(
  defineProps<{
    initials: string
    name: string
    meta?: string
    status?: string
    statusColor?: 'orange' | 'blue' | 'green' | 'red' | 'gray'
  }>(),
  {
    meta: undefined,
    status: undefined,
    statusColor: 'gray',
  },
)
</script>

<template>
  <div class="flex items-center gap-3 py-2">
    <span
      class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-accent/10 text-sm font-semibold text-accent"
    >
      {{ initials }}
    </span>
    <div class="min-w-0 flex-1">
      <div class="flex items-center gap-2">
        <p class="truncate text-sm font-semibold text-heading">{{ name }}</p>
        <UiBadge v-if="status" :text="status" :color="statusColor" />
      </div>
      <p v-if="meta" class="truncate text-xs text-body">{{ meta }}</p>
    </div>
  </div>
</template>
