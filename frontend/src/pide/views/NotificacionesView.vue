<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { onBeforeUnmount, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import EncabezadoPantalla from '../components/EncabezadoPantalla.vue'
import { fechaCorta } from '../lib/formato'
import { useMarcaStore } from '../stores/marca'
import { useNotificacionesStore } from '../stores/notificaciones'

/** La campanita (RN-51). Al salir, todo queda como leído. */
const marca = useMarcaStore()
const notificaciones = useNotificacionesStore()
const router = useRouter()

onMounted(() => notificaciones.cargar())
onBeforeUnmount(() => notificaciones.marcarTodas())

function abrir(idPedido: number): void {
  router.push({ name: 'pedido', params: { slug: marca.slug, id: idPedido } })
}
</script>

<template>
  <main class="pb-10">
    <EncabezadoPantalla titulo="Avisos" :volver-a="`/${marca.slug}/inicio`" />

    <div v-if="!notificaciones.lista.length" class="mt-20 px-5 text-center">
      <span class="mx-auto flex size-20 items-center justify-center rounded-full bg-morado-50">
        <Icon icon="tabler:bell" class="size-10 text-morado-500" />
      </span>
      <p class="mt-4 font-semibold">No tienes avisos</p>
      <p class="mt-1 text-sm text-suave">Aquí verás cómo van tus pedidos.</p>
    </div>

    <ul v-else class="space-y-2 px-5">
      <li v-for="n in notificaciones.lista" :key="n.id">
        <button
          type="button"
          class="flex w-full items-start gap-3 rounded-3xl p-4 text-left ring-1"
          :class="n.leida ? 'bg-white ring-linea' : 'bg-morado-50 ring-morado-100'"
          @click="abrir(n.idPedido)"
        >
          <span
            class="flex size-10 shrink-0 items-center justify-center rounded-2xl"
            :class="n.leida ? 'bg-fondo text-suave' : 'bg-morado-700 text-white'"
          >
            <Icon icon="tabler:motorbike" class="size-5" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="block font-semibold text-tinta">{{ n.titulo }}</span>
            <span class="block text-sm text-suave">{{ n.mensaje }}</span>
          </span>
          <span class="shrink-0 text-xs text-suave">{{ fechaCorta(n.creadaEn) }}</span>
        </button>
      </li>
    </ul>
  </main>
</template>
