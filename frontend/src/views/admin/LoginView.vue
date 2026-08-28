<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAdminAuthStore } from '@/stores/adminAuth'
import UiInput from '@/components/ui/UiInput.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiAlert from '@/components/ui/UiAlert.vue'
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
          <span class="text-lg font-bold text-heading">inDriver</span>
        </div>

        <h1 class="text-2xl font-semibold text-heading">Bienvenido de nuevo</h1>
        <p class="mt-1 text-sm text-gray-500">Ingresa tus datos para continuar.</p>

        <form class="mt-8 space-y-5" @submit.prevent="onSubmit">
          <UiInput
            v-model="email"
            label="Correo electrónico"
            type="email"
            required
            autocomplete="email"
            placeholder="admin@indriver.com"
          />

          <UiInput
            v-model="password"
            label="Contraseña"
            type="password"
            required
            autocomplete="current-password"
            placeholder="********"
          />

          <div class="flex items-center justify-between text-sm">
            <label class="flex items-center gap-2 text-heading">
              <input
                v-model="rememberMe"
                type="checkbox"
                class="h-4 w-4 rounded border-gray-300 text-accent focus:ring-accent"
              />
              Recordarme
            </label>
            <RouterLink
              :to="{ name: 'admin-forgot-password' }"
              class="text-accent hover:underline"
            >
              ¿Olvidaste tu contraseña?
            </RouterLink>
          </div>

          <UiAlert v-if="error" variant="error">{{ error }}</UiAlert>

          <UiButton type="submit" :disabled="loading">Iniciar sesión</UiButton>
        </form>
      </div>
    </div>
  </main>
</template>
