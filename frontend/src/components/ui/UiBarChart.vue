<script setup lang="ts">
import { computed, ref } from 'vue'

const props = defineProps<{
  data: Array<{ label: string; value: number }>
  unit?: string
}>()

const max = computed(() => Math.max(...props.data.map((d) => d.value), 1))
const hovered = ref<number | null>(null)
</script>

<template>
  <div class="flex h-48 items-end gap-3">
    <div
      v-for="(item, index) in data"
      :key="item.label"
      class="relative flex flex-1 flex-col items-center gap-2"
      @mouseenter="hovered = index"
      @mouseleave="hovered = null"
      @touchstart="hovered = index"
    >
      <div
        v-if="hovered === index"
        class="absolute -top-8 rounded-lg bg-brand-dark px-2 py-1 text-xs font-semibold whitespace-nowrap text-white"
      >
        {{ item.value }}{{ unit }}
      </div>
      <div class="flex h-36 w-full items-end">
        <div
          class="w-full rounded-t-md transition-colors"
          :class="hovered === index ? 'bg-brand-blue' : 'bg-brand-blue/25'"
          :style="{ height: `${(item.value / max) * 100}%` }"
        />
      </div>
      <span class="text-xs font-medium text-black/50">{{ item.label }}</span>
    </div>
  </div>
</template>
