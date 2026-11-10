<script setup lang="ts">
import { ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import BandejaWhatsapp from '@/components/whatsapp/BandejaWhatsapp.vue'

/**
 * Panel de chat de WhatsApp del Panel (spec tenant/032, paso 3, y tenant/031, RN-54): la misma
 * bandeja que la pantalla de Conversaciones, en una columna, para que el despachador tenga el chat a
 * la vista sin dejar el mapa.
 *
 * Monta la bandeja la primera vez que se abre y la conserva después: así no se pide la lista ni se
 * abre la suscripción a quien nunca abre el chat, y quien lo cierra y lo vuelve a abrir encuentra la
 * conversación donde la dejó. `Escape` no lo cierra, para no perder lo que se está escribiendo.
 */
const props = defineProps<{ abierto: boolean }>()

const montado = ref(props.abierto)

watch(
  () => props.abierto,
  (abierto) => {
    if (abierto) montado.value = true
  },
)
</script>

<template>
  <aside
    class="fixed right-0 top-(--alto-banda) z-30 flex h-[calc(100vh-var(--alto-banda))] w-[26rem] flex-col bg-white shadow-xl transition-transform duration-[400ms] ease-in-out"
    :class="abierto ? 'translate-x-0' : 'translate-x-full'"
    :inert="!abierto"
    aria-label="Chat de WhatsApp"
  >
    <header class="flex items-center gap-2 border-b border-default px-4 py-3">
      <Icon
        icon="tabler:brand-whatsapp"
        width="18"
        height="18"
        class="text-whatsapp"
        aria-hidden="true"
      />
      <h2 class="text-xs font-semibold uppercase tracking-wide text-body">WhatsApp</h2>
    </header>

    <div class="min-h-0 flex-1">
      <BandejaWhatsapp v-if="montado" compacta />
    </div>
  </aside>
</template>
