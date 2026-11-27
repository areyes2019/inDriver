<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api'
import type { Pedido } from '../api/tipos'
import BannerFlotilla from '../components/BannerFlotilla.vue'
import BotonServicio from '../components/BotonServicio.vue'
import CabeceraMarca from '../components/CabeceraMarca.vue'
import { mensajeDe } from '../lib/estados'
import { useMarcaStore } from '../stores/marca'
import { useNotificacionesStore } from '../stores/notificaciones'

const marca = useMarcaStore()
const notificaciones = useNotificacionesStore()
const router = useRouter()
const activo = ref<Pedido | null>(null)

onMounted(async () => {
  const [activos] = await Promise.all([api.pedidos('activos'), notificaciones.cargar()])
  activo.value = activos[0] ?? null
})

function pedir(tipo: 'enviar' | 'recibir'): void {
  router.push({ name: 'pedir', params: { slug: marca.slug, tipo } })
}
</script>

<template>
  <main>
    <CabeceraMarca con-campana>
      <BannerFlotilla :url="marca.marca?.bannerUrl ?? null" :nombre="marca.nombre" />
    </CabeceraMarca>

    <section class="relative -mt-6 rounded-t-[2rem] bg-fondo px-5 pt-7 pb-6">
      <RouterLink
        v-if="activo"
        :to="{ name: 'pedido', params: { slug: marca.slug, id: activo.id } }"
        class="mb-6 flex items-center gap-3 rounded-2xl bg-morado-700 p-4 text-white shadow-flotante"
      >
        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-white/15">
          <Icon icon="tabler:motorbike" class="size-6 text-amarillo-500" />
        </span>
        <span class="min-w-0">
          <span class="block text-sm text-morado-200">Pedido {{ activo.numero }}</span>
          <span class="block truncate font-semibold">{{ mensajeDe(activo.estado) }}</span>
        </span>
        <Icon icon="tabler:chevron-right" class="ml-auto size-6 shrink-0" />
      </RouterLink>

      <h1 class="text-center font-titulo text-3xl font-extrabold text-tinta">
        ¿Qué necesitas hoy?
      </h1>

      <div class="mt-6 grid grid-cols-2 gap-4">
        <BotonServicio
          v-if="marca.marca?.servicios.enviar"
          tipo="enviar"
          @click="pedir('enviar')"
        />
        <BotonServicio
          v-if="marca.marca?.servicios.recibir"
          tipo="recibir"
          @click="pedir('recibir')"
        />
      </div>

      <p
        v-if="marca.marca && !marca.marca.abiertoAhora"
        class="mt-6 flex items-start gap-2 rounded-2xl bg-amarillo-100 p-4 text-sm text-tinta"
      >
        <Icon icon="tabler:clock" class="mt-0.5 size-5 shrink-0 text-morado-700" />
        <span>
          Ahora estamos cerrados ({{ marca.marca.horario }}). Puedes programar tu pedido para más
          tarde.
        </span>
      </p>
    </section>
  </main>
</template>
