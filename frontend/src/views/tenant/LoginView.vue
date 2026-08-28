<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTenantAuthStore } from '@/stores/tenantAuth'
import banner from '@/assets/banner.webp'
import logo from '@/assets/logo.svg'

const route = useRoute()
const router = useRouter()
const auth = useTenantAuthStore()

const slug = route.params.slug as string

const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

async function onSubmit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(slug, email.value, password.value)
    const destino = auth.usuario?.rol === 'Despachador' ? 'tenant-panel' : 'tenant-clientes-lista'
    router.push({ name: destino, params: { slug } })
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
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Correo electrónico</span>
            <input
              v-model="email"
              type="email"
              required
              autocomplete="email"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
          </label>

          <label class="block">
            <span class="mb-1 block text-sm font-medium text-heading">Contraseña</span>
            <input
              v-model="password"
              type="password"
              required
              autocomplete="current-password"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            />
          </label>

          <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>

          <button
            type="submit"
            :disabled="loading"
            class="w-full rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60"
          >
            Iniciar sesión
          </button>

          <RouterLink
            :to="{ name: 'tenant-forgot-password', params: { slug } }"
            class="block text-center text-sm text-accent hover:underline"
          >
            ¿Olvidaste tu contraseña?
          </RouterLink>
        </form>
      </div>
    </div>
  </main>
</template>
