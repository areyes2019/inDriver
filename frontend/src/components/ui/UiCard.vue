<script setup lang="ts">
withDefaults(
  defineProps<{
    title?: string
    subtitle?: string
    variant?: 'default' | 'dark-header'
  }>(),
  {
    title: undefined,
    subtitle: undefined,
    variant: 'default',
  },
)
</script>

<template>
  <section class="overflow-hidden rounded-2xl bg-white shadow-sm shadow-black/5">
    <header
      v-if="variant === 'dark-header'"
      class="flex items-center justify-between gap-3 bg-heading px-5 py-4"
    >
      <slot name="header">
        <div class="flex items-center gap-3">
          <span
            v-if="$slots.icon"
            class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/10 text-white"
          >
            <slot name="icon" />
          </span>
          <div>
            <h2 class="text-sm font-semibold text-white">{{ title }}</h2>
            <p v-if="subtitle" class="text-xs text-white/60">{{ subtitle }}</p>
          </div>
        </div>
      </slot>
      <slot name="header-end" />
    </header>

    <div class="p-5">
      <header
        v-if="variant === 'default' && (title || $slots.header)"
        class="mb-4 flex items-center justify-between gap-2"
      >
        <slot name="header">
          <h2 class="text-base font-semibold text-heading">{{ title }}</h2>
        </slot>
      </header>
      <slot />
    </div>
  </section>
</template>
