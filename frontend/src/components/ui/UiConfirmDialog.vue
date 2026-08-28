<script setup lang="ts">
withDefaults(
  defineProps<{
    open: boolean
    message: string
    title?: string
    confirmLabel?: string
    cancelLabel?: string
  }>(),
  {
    title: 'Confirmar acción',
    confirmLabel: 'Confirmar',
    cancelLabel: 'Cancelar',
  },
)

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      role="dialog"
      aria-modal="true"
      :aria-label="title"
    >
      <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-lg shadow-black/10">
        <h2 class="text-base font-semibold text-brand-dark">{{ title }}</h2>
        <p class="mt-2 text-sm text-black/70">{{ message }}</p>
        <div class="mt-5 flex justify-end gap-3">
          <button
            type="button"
            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-brand-dark hover:bg-black/5"
            @click="emit('cancel')"
          >
            {{ cancelLabel }}
          </button>
          <button
            type="button"
            class="rounded-lg bg-brand-blue px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90"
            @click="emit('confirm')"
          >
            {{ confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
