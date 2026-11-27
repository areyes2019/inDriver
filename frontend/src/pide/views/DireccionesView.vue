<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { computed, onMounted, ref } from 'vue'
import { api } from '../api'
import type { Direccion } from '../api/tipos'
import EncabezadoPantalla from '../components/EncabezadoPantalla.vue'
import { useMarcaStore } from '../stores/marca'

/** Direcciones favoritas (6.7, RN-56): Casa y Trabajo fijas, más otras. Máximo 10. */
const marca = useMarcaStore()
const direcciones = ref<Direccion[]>([])
const cargando = ref(true)

onMounted(async () => {
  direcciones.value = await api.direcciones()
  cargando.value = false
})

const fijas = computed(() =>
  (['Casa', 'Trabajo'] as const).map((alias) => ({
    alias,
    direccion: direcciones.value.find((d) => d.alias === alias) ?? null,
  })),
)
const otras = computed(() =>
  direcciones.value.filter((d) => d.alias !== 'Casa' && d.alias !== 'Trabajo'),
)
const lleno = computed(() => direcciones.value.length >= 10)
</script>

<template>
  <main class="pb-10">
    <EncabezadoPantalla titulo="Mis direcciones" :volver-a="`/${marca.slug}/cuenta`" />

    <div v-if="!cargando" class="space-y-3 px-5">
      <RouterLink
        v-for="f in fijas"
        :key="f.alias"
        :to="{
          name: 'direccion',
          params: { slug: marca.slug, id: f.direccion ? f.direccion.id : 'nueva' },
          query: f.direccion ? {} : { alias: f.alias },
        }"
        class="flex min-h-18 items-center gap-3 rounded-3xl bg-white p-4 ring-1 ring-linea active:bg-morado-50"
      >
        <span
          class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-morado-50 text-morado-700"
        >
          <Icon :icon="f.alias === 'Casa' ? 'tabler:home-2' : 'tabler:briefcase'" class="size-6" />
        </span>
        <div class="min-w-0">
          <p class="font-semibold">{{ f.alias }}</p>
          <p class="truncate text-sm" :class="f.direccion ? 'text-suave' : 'text-morado-700'">
            {{ f.direccion?.direccion ?? 'Agregar' }}
          </p>
        </div>
        <Icon icon="tabler:chevron-right" class="ml-auto size-5 shrink-0 text-suave" />
      </RouterLink>

      <RouterLink
        v-for="d in otras"
        :key="d.id"
        :to="{ name: 'direccion', params: { slug: marca.slug, id: d.id } }"
        class="flex min-h-18 items-center gap-3 rounded-3xl bg-white p-4 ring-1 ring-linea active:bg-morado-50"
      >
        <span
          class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-morado-50 text-morado-700"
        >
          <Icon icon="tabler:star" class="size-6" />
        </span>
        <div class="min-w-0">
          <p class="font-semibold">{{ d.alias }}</p>
          <p class="truncate text-sm text-suave">{{ d.direccion }}</p>
        </div>
        <Icon icon="tabler:chevron-right" class="ml-auto size-5 shrink-0 text-suave" />
      </RouterLink>

      <RouterLink
        v-if="!lleno"
        :to="{ name: 'direccion', params: { slug: marca.slug, id: 'nueva' } }"
        class="flex min-h-14 items-center justify-center gap-2 rounded-3xl border-2 border-dashed border-morado-200 font-semibold text-morado-700"
      >
        <Icon icon="tabler:plus" class="size-5" /> Agregar otra dirección
      </RouterLink>
      <p v-else class="text-center text-sm text-suave">Puedes guardar hasta 10 direcciones.</p>
    </div>
  </main>
</template>
