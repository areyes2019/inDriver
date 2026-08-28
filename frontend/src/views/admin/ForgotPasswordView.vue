<script setup lang="ts">
import { ref } from 'vue'
import { useAdminAuthStore } from '@/stores/adminAuth'

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
  <main class="admin-auth">
    <form @submit.prevent="onSubmit">
      <h1>Recuperar contraseña</h1>

      <label>
        Correo
        <input v-model="email" type="email" required autocomplete="email" />
      </label>

      <p v-if="message">{{ message }}</p>

      <button type="submit" :disabled="loading">Enviar enlace</button>

      <RouterLink :to="{ name: 'admin-login' }">Volver a iniciar sesión</RouterLink>
    </form>
  </main>
</template>
