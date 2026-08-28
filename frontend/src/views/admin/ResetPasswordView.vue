<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAdminAuthStore } from '@/stores/adminAuth'
import UiInput from '@/components/ui/UiInput.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiAlert from '@/components/ui/UiAlert.vue'
import banner from '@/assets/banner.webp'
import logo from '@/assets/logo.svg'

const route = useRoute()
const router = useRouter()
const auth = useAdminAuthStore()

const token = String(route.params.token ?? '')
const email = ref(String(route.query.email ?? ''))
const password = ref('')
const passwordConfirmation = ref('')
const error = ref('')
const loading = ref(false)

async function onSubmit() {
  error.value = ''
  loading.value = true
  try {
    await auth.resetPassword({
      token,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    router.push({ name: 'admin-login' })
  } catch {
    error.value = 'El enlace no es válido o ya expiró.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <main class="flex h-screen bg-white font-sans">
    <div class="hidden h-full w-1/2 lg:block">
      <img :src="banner" alt="" class="h-full w-full object-cover" />
    </div>

    <div
      class="flex h-full w-full flex-col items-center justify-center overflow-y-auto px-6 py-12 lg:w-1/2"
    >
      <div class="w-full max-w-sm">
        <div class="mb-8 flex items-center gap-2">
          <img :src="logo" alt="" class="h-8 w-8" />
          <span class="text-lg font-bold text-heading">inDriver</span>
        </div>

        <h1 class="text-2xl font-semibold text-heading">Nueva contraseña</h1>
        <p class="mt-1 text-sm text-gray-500">Elige una contraseña nueva para tu cuenta.</p>

        <form class="mt-8 space-y-5" @submit.prevent="onSubmit">
          <UiInput v-model="email" label="Correo electrónico" type="email" required autocomplete="email" />

          <UiInput
            v-model="password"
            label="Nueva contraseña"
            type="password"
            required
            autocomplete="new-password"
            :minlength="8"
            placeholder="********"
          />

          <UiInput
            v-model="passwordConfirmation"
            label="Confirmar contraseña"
            type="password"
            required
            autocomplete="new-password"
            :minlength="8"
            placeholder="********"
          />

          <UiAlert v-if="error" variant="error">{{ error }}</UiAlert>

          <UiButton type="submit" :disabled="loading">Guardar</UiButton>
        </form>
      </div>
    </div>
  </main>
</template>
