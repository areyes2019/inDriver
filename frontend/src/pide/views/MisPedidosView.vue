<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api'
import type { GrupoPedidos, Pedido } from '../api/tipos'
import TarjetaPedido from '../components/TarjetaPedido.vue'
import { useAsistenteStore } from '../stores/asistente'
import { useMarcaStore } from '../stores/marca'

const marca = useMarcaStore()
const asistente = useAsistenteStore()
const router = useRouter()

const pestanas: { valor: GrupoPedidos; texto: string }[] = [
  { valor: 'activos', texto: 'Activos' },
  { valor: 'completados', texto: 'Completados' },
  { valor: 'cancelados', texto: 'Cancelados' },
]
const grupo = ref<GrupoPedidos>('activos')
const pedidos = ref<Pedido[]>([])
const cargando = ref(true)

watch(
  grupo,
  async (g) => {
    cargando.value = true
    pedidos.value = await api.pedidos(g)
    cargando.value = false
  },
  { immediate: true },
)

function abrir(p: Pedido): void {
  const nombre = grupo.value === 'activos' ? 'pedido' : 'detalle'
  router.push({ name: nombre, params: { slug: marca.slug, id: p.id } })
}

/** RN-55: mismos datos, precio de hoy. */
function repetir(p: Pedido): void {
  asistente.repetir(p)
  router.push({
    name: 'pedir',
    params: { slug: marca.slug, tipo: p.tipo === 'ENVIAR' ? 'enviar' : 'recibir' },
    query: { repetir: '1' },
  })
}
</script>

<template>
  <main class="pt-seguro px-5 pb-6">
    <h1 class="mt-3 font-titulo text-3xl font-extrabold">Mis pedidos</h1>

    <div class="mt-5 grid grid-cols-3 gap-1 rounded-2xl bg-morado-100/60 p-1" role="tablist">
      <button
        v-for="p in pestanas"
        :key="p.valor"
        type="button"
        role="tab"
        :aria-selected="grupo === p.valor"
        class="min-h-11 rounded-xl text-sm font-semibold transition-colors"
        :class="grupo === p.valor ? 'bg-white text-morado-700 shadow-tarjeta' : 'text-suave'"
        @click="grupo = p.valor"
      >
        {{ p.texto }}
      </button>
    </div>

    <div v-if="cargando" class="mt-5 space-y-3" aria-busy="true">
      <div v-for="i in 2" :key="i" class="h-40 animate-pulse rounded-3xl bg-white" />
    </div>

    <div v-else-if="!pedidos.length" class="mt-16 text-center">
      <span class="mx-auto flex size-20 items-center justify-center rounded-full bg-morado-50">
        <Icon icon="tabler:list-details" class="size-10 text-morado-500" />
      </span>
      <p class="mt-4 font-semibold">
        {{
          grupo === 'activos'
            ? 'No tienes pedidos en curso'
            : grupo === 'completados'
              ? 'Aún no tienes pedidos completados'
              : 'No tienes pedidos cancelados'
        }}
      </p>
      <RouterLink
        v-if="grupo === 'activos'"
        :to="{ name: 'inicio', params: { slug: marca.slug } }"
        class="mt-3 inline-flex min-h-11 items-center font-semibold text-morado-700 underline underline-offset-2"
      >
        Pedir un mandado
      </RouterLink>
    </div>

    <ul v-else class="mt-5 space-y-3">
      <li v-for="p in pedidos" :key="p.id">
        <TarjetaPedido :pedido="p" class="cursor-pointer" @click="abrir(p)">
          <button
            v-if="grupo !== 'activos'"
            type="button"
            class="ml-auto flex min-h-11 items-center gap-1.5 rounded-xl bg-morado-50 px-4 text-sm font-semibold text-morado-700"
            @click.stop="repetir(p)"
          >
            <Icon icon="tabler:repeat" class="size-4" /> Repetir
          </button>
          <span
            v-else
            class="ml-auto flex items-center gap-1 text-sm font-semibold text-morado-700"
          >
            Ver <Icon icon="tabler:chevron-right" class="size-4" />
          </span>
        </TarjetaPedido>
      </li>
    </ul>
  </main>
</template>
