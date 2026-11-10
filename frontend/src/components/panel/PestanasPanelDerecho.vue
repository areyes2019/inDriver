<script setup lang="ts">
import { Icon } from '@iconify/vue'

/**
 * Pila de pestañas del lado derecho del Panel (spec tenant/032, paso 3): Flotilla arriba y WhatsApp
 * debajo. Va pegada al borde izquierdo del panel derecho abierto o, si no hay ninguno, al borde de la
 * ventana: se coloca con `--ancho-panel-derecho`, que pone `PanelView`, y se desliza con el panel.
 */
export type PanelDerecho = 'flotilla' | 'chat'

defineProps<{
  abierto: PanelDerecho | null
  /** Conversaciones de WhatsApp esperando a una persona (spec 031, RN-55). */
  esperando: number
  /** Solo quien puede ver la bandeja tiene pestaña de WhatsApp (spec 032, RN-10). */
  mostrarChat: boolean
}>()

const emit = defineEmits<{ alternar: [panel: PanelDerecho] }>()

const clasePestana =
  'relative flex h-9 w-9 items-center justify-center rounded-l-lg border border-r-0 border-default shadow-md transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent'
</script>

<template>
  <div
    class="fixed right-(--ancho-panel-derecho) top-[calc(var(--alto-banda)+0.75rem)] z-30 flex flex-col gap-2 transition-[right] duration-[400ms] ease-in-out"
  >
    <button
      type="button"
      :class="[
        clasePestana,
        abierto === 'flotilla'
          ? 'bg-accent-soft text-accent'
          : 'bg-white text-body hover:bg-black/5',
      ]"
      :aria-expanded="abierto === 'flotilla'"
      :aria-label="
        abierto === 'flotilla' ? 'Ocultar panel de flotilla' : 'Mostrar panel de flotilla'
      "
      @click="emit('alternar', 'flotilla')"
    >
      <Icon icon="tabler:car" width="20" height="20" aria-hidden="true" />
    </button>

    <button
      v-if="mostrarChat"
      type="button"
      :class="[
        clasePestana,
        'text-whatsapp',
        abierto === 'chat' ? 'bg-accent-soft' : 'bg-white hover:bg-black/5',
      ]"
      :aria-expanded="abierto === 'chat'"
      :aria-label="
        (abierto === 'chat' ? 'Ocultar chat de WhatsApp' : 'Mostrar chat de WhatsApp') +
        (esperando ? ` (${esperando} esperando)` : '')
      "
      @click="emit('alternar', 'chat')"
    >
      <Icon icon="tabler:brand-whatsapp" width="22" height="22" aria-hidden="true" />
      <span
        v-if="esperando"
        aria-hidden="true"
        class="absolute -left-1.5 -top-1.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold leading-none text-white"
        >{{ esperando }}</span
      >
    </button>
  </div>
</template>
