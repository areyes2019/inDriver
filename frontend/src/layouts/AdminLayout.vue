<script setup lang="ts">
import { computed } from 'vue'
import { LayoutDashboard, Palette } from '@lucide/vue'
import UiSidebar from '@/components/ui/UiSidebar.vue'

const items = computed(() => {
  const base = [{ label: 'Dashboard', to: '/admin', icon: LayoutDashboard }]

  if (import.meta.env.DEV) {
    base.push({ label: 'Style guide', to: '/admin/style-guide', icon: Palette })
  }

  return base
})
</script>

<template>
  <div class="flex h-screen overflow-hidden bg-black/[0.03]">
    <UiSidebar logo-text="inDriver" :items="items" />
    <main class="min-w-0 flex-1 overflow-y-auto p-4 pt-20 md:p-8">
      <slot />
    </main>
  </div>
</template>
