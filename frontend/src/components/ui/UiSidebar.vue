<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import type { Component } from 'vue'
import { ChevronLeft, ChevronRight, Menu, X } from '@lucide/vue'

defineProps<{
  logoText: string
  items: Array<{ label: string; to: string; icon: Component }>
}>()

const collapsed = ref(false)
const mobileOpen = ref(false)
</script>

<template>
  <button
    type="button"
    class="fixed top-4 left-4 z-30 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-dark text-white shadow-lg md:hidden"
    :aria-expanded="mobileOpen"
    aria-label="Abrir menú"
    @click="mobileOpen = true"
  >
    <Menu class="h-5 w-5" />
  </button>

  <div
    v-if="mobileOpen"
    class="fixed inset-0 z-40 bg-black/40 md:hidden"
    @click="mobileOpen = false"
  />

  <aside
    class="fixed inset-y-0 left-0 z-50 flex h-full flex-col gap-6 bg-brand-dark py-6 text-white transition-all duration-200 md:static md:translate-x-0"
    :class="[
      collapsed ? 'md:w-20' : 'md:w-64',
      mobileOpen ? 'w-64 translate-x-0 px-4' : '-translate-x-full px-4 md:translate-x-0',
    ]"
  >
    <div class="flex items-center justify-between px-1">
      <span class="truncate text-lg font-bold" :class="collapsed && 'md:hidden'">
        {{ logoText }}
      </span>
      <button
        type="button"
        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-white/70 hover:bg-white/10 md:hidden"
        aria-label="Cerrar menú"
        @click="mobileOpen = false"
      >
        <X class="h-5 w-5" />
      </button>
    </div>

    <hr class="border-white/10" />

    <nav class="flex flex-1 flex-col gap-1">
      <RouterLink
        v-for="item in items"
        :key="item.to"
        :to="item.to"
        class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-white/70 transition-colors hover:bg-white/10 hover:text-white"
        active-class="bg-brand-blue text-white"
        @click="mobileOpen = false"
      >
        <component :is="item.icon" class="h-5 w-5 shrink-0" />
        <span :class="collapsed && 'md:hidden'">{{ item.label }}</span>
      </RouterLink>
    </nav>

    <button
      type="button"
      class="hidden items-center justify-center gap-2 rounded-xl py-2 text-white/70 hover:bg-white/10 hover:text-white md:flex"
      :aria-label="collapsed ? 'Expandir menú' : 'Colapsar menú'"
      @click="collapsed = !collapsed"
    >
      <ChevronRight v-if="collapsed" class="h-4 w-4" />
      <ChevronLeft v-else class="h-4 w-4" />
    </button>
  </aside>
</template>
