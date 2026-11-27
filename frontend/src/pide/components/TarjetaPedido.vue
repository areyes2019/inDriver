<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { computed } from 'vue'
import type { Pedido } from '../api/tipos'
import { grupoDe, mensajeDe } from '../lib/estados'
import { fechaCorta, pesos } from '../lib/formato'

/** Tarjeta de "Mis pedidos" (6.6). */
const props = defineProps<{ pedido: Pedido }>()
const grupo = computed(() => grupoDe(props.pedido.estado))

const insignia = computed(() => {
  if (grupo.value === 'completados') return 'bg-green-50 text-exito'
  if (grupo.value === 'cancelados') return 'bg-red-50 text-peligro'
  return 'bg-amarillo-100 text-morado-800'
})
</script>

<template>
  <article class="rounded-3xl bg-white p-4 shadow-tarjeta ring-1 ring-linea/60">
    <header class="flex items-center gap-2">
      <span
        class="flex size-10 items-center justify-center rounded-2xl bg-morado-50 text-morado-700"
      >
        <Icon
          :icon="pedido.tipo === 'ENVIAR' ? 'tabler:package-export' : 'tabler:package-import'"
          class="size-5"
        />
      </span>
      <div class="min-w-0">
        <p class="font-semibold text-tinta">{{ pedido.tipo === 'ENVIAR' ? 'Envío' : 'Recibir' }}</p>
        <p class="text-xs text-suave">
          {{ fechaCorta(pedido.programadoPara ?? pedido.creadoEn) }} · {{ pedido.numero }}
        </p>
      </div>
      <span class="ml-auto rounded-full px-2.5 py-1 text-xs font-semibold" :class="insignia">
        {{ mensajeDe(pedido.estado, pedido.sinRepartidor) }}
      </span>
    </header>

    <div class="mt-3 space-y-1 text-sm">
      <p class="flex items-center gap-2 truncate">
        <span class="size-2.5 shrink-0 rounded-full bg-exito" aria-hidden="true" />
        <span class="truncate">{{ pedido.recogida.direccion }}</span>
      </p>
      <p class="flex items-center gap-2 truncate">
        <span class="size-2.5 shrink-0 rounded-full bg-peligro" aria-hidden="true" />
        <span class="truncate">{{ pedido.entrega.direccion }}</span>
      </p>
    </div>

    <footer class="mt-3 flex items-center border-t border-linea pt-3">
      <span class="font-titulo text-lg font-bold">
        {{ pesos(pedido.importeEnvio + pedido.importeProducto) }}
      </span>
      <slot />
    </footer>
  </article>
</template>
