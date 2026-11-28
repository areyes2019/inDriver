<script setup lang="ts">
import { ref } from 'vue'
import { RouterView, useRouter } from 'vue-router'

const router = useRouter()

// Hasta que el router resuelva la navegación inicial no se pinta nada de la app: el guard de la
// raíz del tenant espera a `/me` para decidir entre login y Panel, y ese hueco era un fondo
// blanco (spec tenant/001, enmienda "el slug va inmediatamente después del dominio", RN-S8).
const listo = ref(false)
router.isReady().then(() => {
  listo.value = true
})
</script>

<template>
  <main v-if="!listo" class="flex h-screen items-center justify-center bg-white">
    <img src="/pide/iconos/icono-192.png" alt="" class="size-14 animate-pulse rounded-2xl" />
  </main>
  <RouterView v-else />
</template>
