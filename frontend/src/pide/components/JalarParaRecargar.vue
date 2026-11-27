<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * "Jalar para recargar": con la pantalla hasta arriba, jalar hacia abajo y soltar vuelve a pedir
 * los datos. La app instalada no trae el gesto del navegador (y `overscroll-behavior` lo apaga a
 * propósito), así que se hace aquí. Solo escucha mientras `activo`: en el asistente y en el mapa
 * jalar hacia abajo ya significa otra cosa.
 */
const props = defineProps<{ activo: boolean; recargar: () => Promise<void> }>()

/** Cuánto hay que jalar para que cuente, en píxeles de pantalla. */
const UMBRAL = 70
/** El dedo recorre más que el indicador, para que se sienta elástico. */
const RESISTENCIA = 0.5

const jalon = ref(0)
const recargando = ref(false)
let inicio: number | null = null

const listo = computed(() => jalon.value >= UMBRAL)
const visible = computed(() => recargando.value || jalon.value > 8)
const altura = computed(() => (recargando.value ? UMBRAL : Math.min(jalon.value, UMBRAL * 1.4)))

function alTocar(e: TouchEvent): void {
  if (!props.activo || recargando.value || window.scrollY > 0 || e.touches.length !== 1) {
    inicio = null
    return
  }
  inicio = e.touches[0]!.clientY
}

function alMover(e: TouchEvent): void {
  if (inicio === null) return
  const recorrido = e.touches[0]!.clientY - inicio
  // Si sube, o la página ya se desplazó, es scroll normal.
  if (recorrido <= 0 || window.scrollY > 0) {
    jalon.value = 0
    return
  }
  jalon.value = recorrido * RESISTENCIA
}

async function alSoltar(): Promise<void> {
  if (inicio === null) return
  inicio = null
  const cuenta = listo.value
  jalon.value = 0
  if (!cuenta) return
  recargando.value = true
  try {
    await props.recargar()
  } finally {
    recargando.value = false
  }
}

onMounted(() => {
  window.addEventListener('touchstart', alTocar, { passive: true })
  window.addEventListener('touchmove', alMover, { passive: true })
  window.addEventListener('touchend', alSoltar)
  window.addEventListener('touchcancel', alSoltar)
})

onBeforeUnmount(() => {
  window.removeEventListener('touchstart', alTocar)
  window.removeEventListener('touchmove', alMover)
  window.removeEventListener('touchend', alSoltar)
  window.removeEventListener('touchcancel', alSoltar)
})
</script>

<template>
  <div
    v-if="visible"
    class="pointer-events-none fixed inset-x-0 top-0 z-40 flex justify-center"
    :style="{ transform: `translateY(${altura - 44}px)` }"
    role="status"
    :aria-label="recargando ? 'Actualizando' : 'Suelta para actualizar'"
  >
    <span
      class="pt-seguro mt-2 flex size-11 items-center justify-center rounded-full bg-white text-morado-700 shadow-flotante ring-1 ring-linea"
    >
      <Icon
        icon="tabler:refresh"
        class="size-5 transition-transform"
        :class="{ 'animate-spin': recargando }"
        :style="recargando ? undefined : { transform: `rotate(${jalon * 3}deg)` }"
      />
    </span>
  </div>
</template>
