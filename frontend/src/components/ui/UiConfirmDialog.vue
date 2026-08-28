<script setup lang="ts">
import { ref, watch } from 'vue'

const props = withDefaults(
  defineProps<{
    open: boolean
    message: string
    title?: string
    confirmLabel?: string
    cancelLabel?: string
    requirePassword?: boolean
    passwordError?: string
  }>(),
  {
    title: 'Confirmar acción',
    confirmLabel: 'Confirmar',
    cancelLabel: 'Cancelar',
    requirePassword: false,
    passwordError: '',
  },
)

const emit = defineEmits<{
  confirm: [password?: string]
  cancel: []
}>()

const password = ref('')

watch(
  () => props.open,
  (open) => {
    if (!open) password.value = ''
  },
)

function handleConfirm() {
  emit('confirm', props.requirePassword ? password.value : undefined)
}
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
        <h2 class="text-base font-semibold text-heading">{{ title }}</h2>
        <p class="mt-2 text-sm text-black/70">{{ message }}</p>

        <div v-if="requirePassword" class="mt-4">
          <label class="text-sm font-medium text-heading" for="ui-confirm-dialog-password">
            Contraseña
          </label>
          <input
            id="ui-confirm-dialog-password"
            v-model="password"
            type="password"
            autocomplete="current-password"
            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            @keyup.enter="handleConfirm"
          />
          <p v-if="passwordError" role="alert" class="mt-1 text-sm text-red-600">
            {{ passwordError }}
          </p>
        </div>

        <div class="mt-5 flex justify-end gap-3">
          <button
            type="button"
            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-heading hover:bg-black/5"
            @click="emit('cancel')"
          >
            {{ cancelLabel }}
          </button>
          <button
            type="button"
            class="rounded-lg bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90"
            @click="handleConfirm"
          >
            {{ confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
