<script setup lang="ts">
import { Icon } from '@iconify/vue'
import CabeceraMarca from '../components/CabeceraMarca.vue'
import { formatoTelefono } from '../lib/telefono'
import { useMarcaStore } from '../stores/marca'

/** RN-01: la flotilla todavía no tiene prendida la app. */
const marca = useMarcaStore()
</script>

<template>
  <main class="min-h-dvh">
    <CabeceraMarca />
    <section class="relative -mt-4 rounded-t-[2rem] bg-fondo px-6 pt-10 pb-8 text-center">
      <span class="mx-auto flex size-20 items-center justify-center rounded-full bg-morado-100">
        <Icon icon="tabler:clock" class="size-10 text-morado-700" />
      </span>
      <h1 class="mt-5 font-titulo text-3xl font-extrabold">Muy pronto</h1>
      <p class="mt-2 text-suave">
        {{ marca.nombre }} todavía no recibe pedidos por aquí. Mientras tanto, puedes pedir tu
        mandado por teléfono.
      </p>
      <a
        v-if="marca.marca?.telefonoContacto"
        :href="`tel:${marca.marca.telefonoContacto}`"
        class="mt-7 flex min-h-14 items-center justify-center gap-2 rounded-2xl bg-morado-700 font-semibold text-white shadow-flotante"
      >
        <Icon icon="tabler:phone" class="size-5" />
        Llamar al {{ formatoTelefono(marca.marca.telefonoContacto) }}
      </a>
    </section>
  </main>
</template>
