<script setup lang="ts">
import { reactive, ref } from 'vue'
import axios from 'axios'
import UiAlert from '@/components/ui/UiAlert.vue'
import { useTenantAuthStore } from '@/stores/tenantAuth'

const props = defineProps<{
  slug: string
}>()

const tenantAuth = useTenantAuthStore()

const form = reactive({
  password_actual: '',
  password: '',
  password_confirmation: '',
})

const fieldErrors = reactive<Record<string, string>>({})
const error = ref('')
const success = ref('')
const guardando = ref(false)

async function onSubmit() {
  error.value = ''
  success.value = ''
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  guardando.value = true

  try {
    await tenantAuth.changePassword(props.slug, { ...form })
    success.value = 'Contraseña actualizada correctamente.'
    form.password_actual = ''
    form.password = ''
    form.password_confirmation = ''
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const errors = err.response.data?.errors ?? {}
      for (const [field, messages] of Object.entries(errors)) {
        fieldErrors[field] = (messages as string[])[0] ?? ''
      }
    } else {
      error.value = 'No se pudo actualizar la contraseña, intenta de nuevo.'
    }
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <form class="max-w-lg space-y-5" @submit.prevent="onSubmit">
    <label class="block">
      <span class="mb-1 block text-sm font-medium text-heading">Contraseña actual</span>
      <input
        v-model="form.password_actual"
        type="password"
        autocomplete="current-password"
        required
        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
      />
      <span v-if="fieldErrors.password_actual" class="mt-1 block text-sm text-red-600">
        {{ fieldErrors.password_actual }}
      </span>
    </label>

    <label class="block">
      <span class="mb-1 block text-sm font-medium text-heading">Contraseña nueva</span>
      <input
        v-model="form.password"
        type="password"
        autocomplete="new-password"
        minlength="8"
        required
        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
      />
      <span v-if="fieldErrors.password" class="mt-1 block text-sm text-red-600">
        {{ fieldErrors.password }}
      </span>
    </label>

    <label class="block">
      <span class="mb-1 block text-sm font-medium text-heading">Confirmar contraseña nueva</span>
      <input
        v-model="form.password_confirmation"
        type="password"
        autocomplete="new-password"
        minlength="8"
        required
        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
      />
    </label>

    <UiAlert v-if="error" variant="error">{{ error }}</UiAlert>
    <UiAlert v-if="success" variant="success">{{ success }}</UiAlert>

    <div class="mt-2 border-t border-gray-100 pt-4">
      <button
        type="submit"
        :disabled="guardando"
        class="w-full rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
      >
        Guardar contraseña
      </button>
    </div>
  </form>
</template>
