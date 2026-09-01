<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Icon } from '@iconify/vue'

withDefaults(
  defineProps<{
    logoText: string
    items: Array<{ label: string; to: string }>
    mostrarConfiguracion?: boolean
  }>(),
  {
    mostrarConfiguracion: true,
  },
)

const emit = defineEmits<{
  'click-configuracion': []
}>()

const mobileOpen = ref(false)
</script>

<template>
  <header class="fixed inset-x-0 top-0 z-40">
    <div class="h-1 bg-gradient-to-r from-accent via-[#8b5cf6] to-[#ec4899]" />

    <nav class="h-16 border-b border-default bg-neutral-primary">
      <div class="mx-auto flex h-full max-w-screen-xl items-center gap-4 px-4">
        <span class="text-lg font-bold text-heading">{{ logoText }}</span>

        <ul class="hidden items-center gap-1 md:flex">
          <li v-for="item in items" :key="item.to">
            <RouterLink
              :to="item.to"
              class="block rounded-full px-3 py-1.5 text-sm font-medium text-body hover:bg-black/5"
              active-class="bg-accent text-white hover:bg-accent"
            >
              {{ item.label }}
            </RouterLink>
          </li>
        </ul>

        <div class="ml-auto hidden items-center gap-3 md:flex">
          <slot name="badge" />
          <button
            v-if="mostrarConfiguracion"
            type="button"
            class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-body hover:bg-black/5"
            aria-label="Configuración"
            @click="emit('click-configuracion')"
          >
            <Icon icon="flat-color-icons:settings" width="20" height="20" aria-hidden="true" />
          </button>
          <button
            type="button"
            class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-body hover:bg-black/5"
            aria-label="Notificaciones"
          >
            <Icon icon="fluent-color:alert-badge-24" width="20" height="20" aria-hidden="true" />
          </button>
          <slot name="actions" />
        </div>

        <button
          type="button"
          class="ml-auto inline-flex h-9 w-9 items-center justify-center rounded-lg text-heading hover:bg-black/5 md:hidden"
          :aria-expanded="mobileOpen"
          aria-label="Abrir menú"
          @click="mobileOpen = !mobileOpen"
        >
          <Icon
            v-if="!mobileOpen"
            icon="flat-color-icons:menu"
            width="20"
            height="20"
            aria-hidden="true"
          />
          <Icon v-else icon="flat-color-icons:cancel" width="20" height="20" aria-hidden="true" />
        </button>
      </div>
    </nav>

    <div
      v-if="mobileOpen"
      class="absolute inset-x-0 top-[4.25rem] border-b border-default bg-neutral-primary shadow-lg md:hidden"
    >
      <ul class="flex flex-col px-4 py-3 text-sm font-medium">
        <li v-for="item in items" :key="item.to">
          <RouterLink
            :to="item.to"
            class="block rounded-lg px-3 py-2 text-body"
            active-class="bg-accent text-white"
            @click="mobileOpen = false"
          >
            {{ item.label }}
          </RouterLink>
        </li>
      </ul>
      <div class="flex flex-col gap-3 border-t border-default px-4 py-3">
        <slot name="badge" />
        <slot name="actions" />
      </div>
    </div>
  </header>
</template>
