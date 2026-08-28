<script setup lang="ts">
import { ref } from 'vue'
import { useAdminAuthStore } from '@/stores/adminAuth'
import banner from '@/assets/banner.webp'
import logo from '@/assets/logo.svg'

const auth = useAdminAuthStore()

const email = ref('')
const message = ref('')
const loading = ref(false)

async function onSubmit() {
  loading.value = true
  message.value = ''
  try {
    await auth.forgotPassword(email.value)
    message.value = 'Si el correo existe, se envió un enlace de recuperación.'
  } catch {
    message.value = 'No se pudo enviar el correo, intenta de nuevo.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <main class="flex min-h-screen bg-white font-sans">
    <div class="hidden w-1/2 lg:block">
      <img :src="banner" alt="" class="h-full w-full object-cover" />
    </div>

    <div class="flex w-full flex-col items-center justify-center px-6 py-12 lg:w-1/2">
      <div class="w-full max-w-sm">
        <div class="mb-8 flex items-center gap-2">
          <img :src="logo" alt="" class="h-8 w-8" />
          <span class="text-lg font-bold text-brand-dark">inDriver</span>
        </div>

        <h1 class="text-2xl font-semibold text-brand-dark">Recupera tu contraseña</h1>
        <p class="mt-1 text-sm text-gray-500">
          Escribe tu correo y te enviaremos un enlace para restablecerla.
        </p>

        <form class="mt-8 space-y-5" @submit.prevent="onSubmit">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-brand-dark">Correo electrónico</span>
            <input
              v-model="email"
              type="email"
              required
              autocomplete="email"
              placeholder="admin@indriver.com"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
            />
          </label>

          <p v-if="message" class="text-sm text-brand-dark">{{ message }}</p>

          <button
            type="submit"
            :disabled="loading"
            class="w-full rounded-lg bg-brand-blue px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60"
          >
            Enviar enlace
          </button>

          <RouterLink
            :to="{ name: 'admin-login' }"
            class="block text-center text-sm text-brand-blue hover:underline"
          >
            Volver a iniciar sesión
          </RouterLink>
        </form>
      </div>
    </div>
  </main>
</template>
