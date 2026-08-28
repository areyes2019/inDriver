<script setup lang="ts">
import { ref } from 'vue'
import { useAdminAuthStore } from '@/stores/adminAuth'
import UiInput from '@/components/ui/UiInput.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiAlert from '@/components/ui/UiAlert.vue'
import banner from '@/assets/banner.webp'
import logo from '@/assets/logo.svg'

const auth = useAdminAuthStore()

const email = ref('')
const message = ref('')
const messageIsError = ref(false)
const loading = ref(false)

async function onSubmit() {
  loading.value = true
  message.value = ''
  try {
    await auth.forgotPassword(email.value)
    messageIsError.value = false
    message.value = 'Si el correo existe, se envió un enlace de recuperación.'
  } catch {
    messageIsError.value = true
    message.value = 'No se pudo enviar el correo, intenta de nuevo.'
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

        <h1 class="text-2xl font-semibold text-heading">Recupera tu contraseña</h1>
        <p class="mt-1 text-sm text-gray-500">
          Escribe tu correo y te enviaremos un enlace para restablecerla.
        </p>

        <form class="mt-8 space-y-5" @submit.prevent="onSubmit">
          <UiInput
            v-model="email"
            label="Correo electrónico"
            type="email"
            required
            autocomplete="email"
            placeholder="admin@indriver.com"
          />

          <UiAlert v-if="message" :variant="messageIsError ? 'error' : 'success'">
            {{ message }}
          </UiAlert>

          <UiButton type="submit" :disabled="loading">Enviar enlace</UiButton>

          <RouterLink
            :to="{ name: 'admin-login' }"
            class="block text-center text-sm text-accent hover:underline"
          >
            Volver a iniciar sesión
          </RouterLink>
        </form>
      </div>
    </div>
  </main>
</template>
