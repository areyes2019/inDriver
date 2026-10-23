<script setup lang="ts">
import { computed, ref, watchEffect } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import HelloWorld from './components/HelloWorld.vue'
import logo from '@/assets/logo.svg'

const route = useRoute()
const router = useRouter()

// Hasta que el router resuelva la navegación inicial no se pinta nada de la app: el guard de la
// raíz del tenant espera a `/me` para decidir entre login y Panel, y ese hueco era un fondo
// blanco (spec tenant/001, enmienda "el slug va inmediatamente después del dominio", RN-S8).
const listo = ref(false)
router.isReady().then(() => {
  listo.value = true
})

// El scaffold inicial de Vite/Vue (logo + "You did it!" + nav Home/About) solo debe verse en la
// ruta raíz de demo ("/", "/about"); cualquier panel real de la app (admin o tenant) lo omite.
// Se decide por el nombre de la ruta y no por el path: desde la enmienda de arriba el tenant vive
// en `/:slug`, que no tiene prefijo por el que preguntar.
const isAppRoute = computed(() => {
  const nombre = String(route.name ?? '')
  return !listo.value || nombre.startsWith('admin-') || nombre.startsWith('tenant-')
})

watchEffect(() => {
  document.body.classList.toggle('is-admin', isAppRoute.value)
})
</script>

<template>
  <main v-if="!listo" class="flex h-screen items-center justify-center bg-white">
    <img :src="logo" alt="" class="h-12 w-12" />
  </main>
  <RouterView v-else-if="isAppRoute" />
  <div v-else class="app-shell">
    <header>
      <img alt="Vue logo" class="logo" src="@/assets/logo.svg" width="125" height="125" />

      <div class="wrapper">
        <HelloWorld msg="You did it!" />

        <nav>
          <RouterLink to="/">Home</RouterLink>
          <RouterLink to="/about">About</RouterLink>
        </nav>
      </div>
    </header>

    <RouterView />
  </div>
</template>

<style scoped>
header {
  line-height: 1.5;
  max-height: 100vh;
}

.logo {
  display: block;
  margin: 0 auto 2rem;
}

nav {
  width: 100%;
  font-size: 12px;
  text-align: center;
  margin-top: 2rem;
}

nav a.router-link-exact-active {
  color: var(--color-text);
}

nav a.router-link-exact-active:hover {
  background-color: transparent;
}

nav a {
  display: inline-block;
  padding: 0 1rem;
  border-left: 1px solid var(--color-border);
}

nav a:first-of-type {
  border: 0;
}

@media (min-width: 1024px) {
  header {
    display: flex;
    place-items: center;
    padding-right: calc(var(--section-gap) / 2);
  }

  .logo {
    margin: 0 2rem 0 0;
  }

  header .wrapper {
    display: flex;
    place-items: flex-start;
    flex-wrap: wrap;
  }

  nav {
    text-align: left;
    margin-left: -1rem;
    font-size: 1rem;

    padding: 1rem 0;
    margin-top: 1rem;
  }
}
</style>
