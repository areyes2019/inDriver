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
    await auth.resetPassword(slug, {
      token,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    router.push({ name: 'tenant-login', params: { slug } })
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
          <span class="text-lg font-bold text-brand-dark">inDriver</span>
        </div>

        <h1 class="text-2xl font-semibold text-brand-dark">Nueva contraseña</h1>
        <p class="mt-1 text-sm text-gray-500">Elige una contraseña nueva para tu cuenta.</p>

        <form class="mt-8 space-y-5" @submit.prevent="onSubmit">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-brand-dark">Correo electrónico</span>
            <input
              v-model="email"
              type="email"
              required
              autocomplete="email"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
            />
          </label>

          <label class="block">
            <span class="mb-1 block text-sm font-medium text-brand-dark">Nueva contraseña</span>
            <input
              v-model="password"
              type="password"
              required
              autocomplete="new-password"
              minlength="8"
              placeholder="********"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
            />
          </label>

          <label class="block">
            <span class="mb-1 block text-sm font-medium text-brand-dark">Confirmar contraseña</span>
            <input
              v-model="passwordConfirmation"
              type="password"
              required
              autocomplete="new-password"
              minlength="8"
              placeholder="********"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
            />
          </label>

          <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>

          <button
            type="submit"
            :disabled="loading"
            class="w-full rounded-lg bg-brand-blue px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60"
          >
            Guardar
          </button>
        </form>
      </div>
    </div>
  </main>
</template>
