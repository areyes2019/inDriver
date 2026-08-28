<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAdminAuthStore } from '@/stores/adminAuth'
import banner from '@/assets/banner.webp'
import logo from '@/assets/logo.svg'

const auth = useAdminAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
// "Recordarme" es solo visual: no se envía al backend, no hay lógica de sesión persistente (spec 006).
const rememberMe = ref(false)
const error = ref('')
const loading = ref(false)

async function onSubmit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push({ name: 'admin-dashboard' })
  } catch {
    error.value = 'Correo o contraseña incorrectos.'
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
          <span class="text-lg font-bold text-brand-dark">inDriver</span>
        </div>

        <h1 class="text-2xl font-semibold text-brand-dark">Bienvenido de nuevo</h1>
        <p class="mt-1 text-sm text-gray-500">Ingresa tus datos para continuar.</p>

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

          <label class="block">
            <span class="mb-1 block text-sm font-medium text-brand-dark">Contraseña</span>
            <input
              v-model="password"
              type="password"
              required
              autocomplete="current-password"
              placeholder="********"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
            />
          </label>

          <div class="flex items-center justify-between text-sm">
            <label class="flex items-center gap-2 text-brand-dark">
              <input
                v-model="rememberMe"
                type="checkbox"
                class="h-4 w-4 rounded border-gray-300 text-brand-blue focus:ring-brand-blue"
              />
              Recordarme
            </label>
            <RouterLink :to="{ name: 'admin-forgot-password' }" class="text-brand-blue hover:underline">
              ¿Olvidaste tu contraseña?
            </RouterLink>
          </div>

          <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>

          <button
            type="submit"
            :disabled="loading"
            class="w-full rounded-lg bg-brand-blue px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60"
          >
            Iniciar sesión
          </button>
        </form>
      </div>
    </div>
  </main>
</template>
