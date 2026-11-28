<script setup lang="ts">
import { Icon } from '@iconify/vue'
import MaquetaTelefono from './MaquetaTelefono.vue'
import { vAparecer } from '../aparecer'

/**
 * Maqueta de una conversación de WhatsApp con el asistente de la flotilla: el cliente pide, el
 * asistente cotiza y el pedido queda creado. Los mensajes entran uno tras otro cuando la maqueta se
 * ve en pantalla (la clase `aparecido` la pone `v-aparecer` en el propio chat).
 */
const mensajes = [
  {
    de: 'cliente',
    texto: 'Hola, ¿me recogen un paquete en Av. Tecnológico 100 y lo llevan a la colonia Alameda?',
  },
  {
    de: 'bot',
    texto: 'Claro 🙌 De Av. Tecnológico 100 a Alameda son 4.2 km. El envío cuesta $45.',
  },
  { de: 'bot', texto: '¿Quién recibe y a qué teléfono le avisamos?' },
  { de: 'cliente', texto: 'Recibe Luis, 461 555 0101' },
  { de: 'bot', texto: '¡Listo! Tu pedido PED-000187 ya está buscando repartidor 🛵' },
]
</script>

<template>
  <MaquetaTelefono etiqueta="Maqueta de un pedido por WhatsApp atendido por el asistente" oscuro>
    <div class="flex items-center gap-2 bg-[#1f2c34] px-3 py-2.5">
      <span class="flex size-8 items-center justify-center rounded-full bg-whatsapp">
        <Icon icon="tabler:robot" class="size-5 text-white" />
      </span>
      <div>
        <p class="text-xs font-semibold text-white">Tu Flotilla</p>
        <p class="text-[9px] text-white/50">Asistente · en línea</p>
      </div>
    </div>

    <div v-aparecer class="chat flex flex-col gap-2 px-3 pt-3">
      <p
        v-for="(mensaje, i) in mensajes"
        :key="i"
        class="mensaje max-w-[85%] rounded-xl px-2.5 py-1.5 text-[10.5px] leading-snug text-white"
        :class="
          mensaje.de === 'cliente'
            ? 'self-end rounded-tr-sm bg-[#005c4b]'
            : 'self-start rounded-tl-sm bg-[#1f2c34]'
        "
        :style="{ animationDelay: `${0.4 + i * 0.9}s` }"
      >
        {{ mensaje.texto }}
      </p>
    </div>

    <div class="absolute inset-x-0 bottom-0 flex items-center gap-2 bg-[#1f2c34] px-3 py-2.5">
      <span class="flex-1 rounded-full bg-[#2a3942] px-3 py-1.5 text-[10px] text-white/40"
        >Mensaje</span
      >
      <span class="flex size-7 items-center justify-center rounded-full bg-whatsapp">
        <Icon icon="tabler:send" class="size-4 text-white" />
      </span>
    </div>
  </MaquetaTelefono>
</template>

<style scoped>
/* Los mensajes esperan ocultos hasta que el contenedor aparece en pantalla. */
.mensaje {
  opacity: 0;
  transform: translateY(8px);
}

.chat.aparecido .mensaje {
  animation: llegar 0.45s ease-out forwards;
}

@keyframes llegar {
  to {
    opacity: 1;
    transform: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .mensaje {
    opacity: 1;
    transform: none;
    animation: none !important;
  }
}
</style>
