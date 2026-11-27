<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { controlesPrototipo } from '../api/mock'

/**
 * RN-07: una versión nueva espera a que el cliente la acepte; no interrumpe lo que está haciendo.
 * En el prototipo lo enciende el panel "Demo"; en la Fase 8 lo enciende el service worker.
 */
function actualizar(): void {
  controlesPrototipo.versionNueva = false
  window.location.reload()
}
</script>

<template>
  <Transition name="pantalla">
    <div
      v-if="controlesPrototipo.versionNueva"
      class="fixed inset-x-3 top-3 z-40 mx-auto flex max-w-md items-center gap-3 rounded-2xl bg-morado-900 p-3 pl-4 text-white shadow-flotante"
      role="status"
    >
      <Icon icon="tabler:sparkles" class="size-6 shrink-0 text-amarillo-500" aria-hidden="true" />
      <p class="text-sm font-medium">Hay una versión nueva</p>
      <button
        type="button"
        class="ml-auto min-h-10 rounded-xl bg-amarillo-500 px-4 text-sm font-semibold text-morado-950"
        @click="actualizar"
      >
        Actualizar
      </button>
    </div>
  </Transition>
</template>
