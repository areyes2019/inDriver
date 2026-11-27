<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { useMarcaStore } from '../stores/marca'

const marca = useMarcaStore()

const pestanas = [
  { ruta: 'inicio', texto: 'Inicio', icono: 'tabler:home' },
  { ruta: 'pedidos', texto: 'Mis pedidos', icono: 'tabler:list-details' },
  { ruta: 'cuenta', texto: 'Mi cuenta', icono: 'tabler:user-circle' },
] as const
</script>

<template>
  <nav
    class="pb-seguro fixed inset-x-0 bottom-0 z-30 mx-auto max-w-md border-t border-linea bg-white/95 backdrop-blur"
    aria-label="Navegación principal"
  >
    <ul class="grid grid-cols-3">
      <li v-for="p in pestanas" :key="p.ruta">
        <RouterLink
          :to="{ name: p.ruta, params: { slug: marca.slug } }"
          class="flex min-h-16 flex-col items-center justify-center gap-0.5 pt-2 text-xs font-medium text-suave"
          active-class="!text-morado-700 !font-semibold"
          :aria-label="p.texto"
        >
          <Icon :icon="p.icono" class="size-7" aria-hidden="true" />
          {{ p.texto }}
        </RouterLink>
      </li>
    </ul>
  </nav>
</template>
