<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'

const abierta = defineModel<boolean>({ required: true })
withDefaults(defineProps<{ titulo?: string; cerrable?: boolean }>(), {
  titulo: undefined,
  cerrable: true,
})

function alTeclado(evento: KeyboardEvent): void {
  if (evento.key === 'Escape') abierta.value = false
}

watch(abierta, (si) => {
  if (si) window.addEventListener('keydown', alTeclado)
  else window.removeEventListener('keydown', alTeclado)
})
onBeforeUnmount(() => window.removeEventListener('keydown', alTeclado))
</script>

<template>
  <Teleport to="body">
    <Transition name="hoja">
      <div
        v-if="abierta"
        class="fixed inset-0 z-50 flex items-end justify-center bg-morado-950/45"
        @click.self="cerrable && (abierta = false)"
      >
        <div
          class="hoja-panel pb-seguro w-full max-w-md rounded-t-[2rem] bg-white px-5 pt-3"
          role="dialog"
          aria-modal="true"
          :aria-label="titulo"
        >
          <div class="mx-auto mb-4 h-1.5 w-10 rounded-full bg-linea" aria-hidden="true" />
          <h2 v-if="titulo" class="mb-3 font-titulo text-2xl font-bold text-tinta">{{ titulo }}</h2>
          <slot />
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
