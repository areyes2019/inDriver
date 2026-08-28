<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAdminAuthStore } from '@/stores/adminAuth'

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
  <main class="admin-auth">
    <form @submit.prevent="onSubmit">
      <h1>Restablecer contraseña</h1>

      <label>
        Correo
        <input v-model="email" type="email" required autocomplete="email" />
      </label>

      <label>
        Nueva contraseña
        <input
          v-model="password"
          type="password"
          required
          autocomplete="new-password"
          minlength="8"
        />
      </label>

      <label>
        Confirmar contraseña
        <input
          v-model="passwordConfirmation"
          type="password"
          required
          autocomplete="new-password"
          minlength="8"
        />
      </label>

      <p v-if="error" role="alert">{{ error }}</p>

      <button type="submit" :disabled="loading">Guardar</button>
    </form>
  </main>
</template>
