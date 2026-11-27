<script setup lang="ts">
import { Icon } from '@iconify/vue'

/**
 * Pasos para instalar a mano: en iPhone siempre (no hay botón que instale), y en Android cuando el
 * navegador no ofrece el aviso, por ejemplo si la liga se abrió dentro de WhatsApp.
 */
defineProps<{ esIphone: boolean; nombre: string }>()
</script>

<template>
  <ol class="space-y-3 rounded-2xl bg-morado-50 p-4 text-left text-sm text-tinta">
    <template v-if="esIphone">
      <li class="flex items-center gap-3">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-white">
          <Icon icon="tabler:share-2" class="size-5 text-morado-700" />
        </span>
        <span>Toca el botón <strong>Compartir</strong> (el cuadro con la flecha hacia arriba)</span>
      </li>
      <li class="flex items-center gap-3">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-white">
          <Icon icon="tabler:square-plus" class="size-5 text-morado-700" />
        </span>
        <span>Baja y elige <strong>Agregar a inicio</strong></span>
      </li>
      <li class="flex items-center gap-3">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-white">
          <Icon icon="tabler:check" class="size-5 text-morado-700" />
        </span>
        <span>Toca <strong>Agregar</strong> y abre {{ nombre }} desde tu pantalla de inicio</span>
      </li>
    </template>
    <template v-else>
      <li class="flex items-center gap-3">
        <span
          class="flex size-9 shrink-0 items-center justify-center rounded-full bg-white font-bold text-morado-700"
        >
          ⋮
        </span>
        <span>
          Toca el menú <strong>⋮</strong> arriba a la derecha. Si abriste la liga desde WhatsApp,
          primero elige <strong>Abrir en Chrome</strong>.
        </span>
      </li>
      <li class="flex items-center gap-3">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-white">
          <Icon icon="tabler:download" class="size-5 text-morado-700" />
        </span>
        <span
          >Elige <strong>Instalar app</strong> o <strong>Agregar a pantalla de inicio</strong></span
        >
      </li>
      <li class="flex items-center gap-3">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-white">
          <Icon icon="tabler:check" class="size-5 text-morado-700" />
        </span>
        <span>Abre {{ nombre }} desde tu pantalla de inicio</span>
      </li>
    </template>
  </ol>
</template>
