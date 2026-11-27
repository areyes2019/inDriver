<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { useMarcaStore } from '../stores/marca'
import { useNotificacionesStore } from '../stores/notificaciones'
import LogoFlotilla from './LogoFlotilla.vue'

/** Encabezado morado con el logo y el nombre de la flotilla (6.2, imagen de referencia). */
withDefaults(defineProps<{ conCampana?: boolean }>(), { conCampana: false })

const marca = useMarcaStore()
const notificaciones = useNotificacionesStore()
</script>

<template>
  <div class="relative overflow-hidden bg-linear-to-b from-morado-800 via-morado-700 to-morado-600">
    <div class="pt-seguro flex items-center gap-3 px-5 pb-1">
      <LogoFlotilla :url="marca.marca?.logoUrl ?? null" :nombre="marca.nombre" :tamano="52" />
      <div class="min-w-0">
        <p class="truncate font-titulo text-2xl leading-tight font-extrabold text-white italic">
          {{ marca.nombre }}
        </p>
        <p class="text-sm font-medium text-morado-200">Moto mandados</p>
      </div>
      <RouterLink
        v-if="conCampana"
        :to="{ name: 'avisos', params: { slug: marca.slug } }"
        class="relative ml-auto flex size-12 items-center justify-center rounded-full text-white active:bg-white/10"
        :aria-label="
          notificaciones.sinLeer ? `Avisos, ${notificaciones.sinLeer} sin leer` : 'Avisos'
        "
      >
        <Icon icon="tabler:bell" class="size-7" aria-hidden="true" />
        <span
          v-if="notificaciones.sinLeer"
          class="absolute top-2.5 right-2.5 size-3 rounded-full bg-amarillo-500 ring-2 ring-morado-700"
        />
      </RouterLink>
    </div>
    <slot>
      <!-- Sin banner: espacio para que la tarjeta blanca que sube encima no tape el nombre. -->
      <div class="h-8" />
    </slot>
  </div>
</template>
