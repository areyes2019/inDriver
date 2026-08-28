<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAdminAuthStore } from '@/stores/adminAuth'

const auth = useAdminAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
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
  <main class="admin-auth">
    <form @submit.prevent="onSubmit">
      <h1>Iniciar sesión</h1>

      <label>
        Correo
        <input v-model="email" type="email" required autocomplete="email" />
      </label>

      <label>
        Contraseña
        <input v-model="password" type="password" required autocomplete="current-password" />
      </label>

      <p v-if="error" role="alert">{{ error }}</p>

      <button type="submit" :disabled="loading">Entrar</button>

      <RouterLink :to="{ name: 'admin-forgot-password' }">Olvidé mi contraseña</RouterLink>
    </form>
  </main>
</template>
