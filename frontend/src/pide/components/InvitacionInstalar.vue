<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { computed } from 'vue'
import { useInstalacion } from '../composables/useInstalacion'
import { useMarcaStore } from '../stores/marca'
import { useUiStore } from '../stores/ui'
import BotonPrincipal from './BotonPrincipal.vue'
import HojaInferior from './HojaInferior.vue'
import LogoFlotilla from './LogoFlotilla.vue'

const ui = useUiStore()
const marca = useMarcaStore()
const { esIphone, puedeInstalarConBoton, instalar, cerrarInstalar } = useInstalacion()

const abierta = computed({
  get: () => ui.hojaInstalar,
  set: (v) => (v ? (ui.hojaInstalar = true) : cerrarInstalar()),
})
</script>

<template>
  <HojaInferior v-model="abierta">
    <div class="flex flex-col items-center text-center">
      <LogoFlotilla :url="marca.marca?.logoUrl ?? null" :nombre="marca.nombre" :tamano="72" />
      <h2 class="mt-3 font-titulo text-2xl font-bold">Agrega {{ marca.nombre }} a tu inicio</h2>
      <p class="mt-1 text-suave">Ábrela con un toque, como cualquier app, sin descargar nada.</p>
    </div>

    <ol v-if="esIphone" class="mt-5 space-y-3 rounded-2xl bg-morado-50 p-4 text-sm text-tinta">
      <li class="flex items-center gap-3">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-white">
          <Icon icon="tabler:share-2" class="size-5 text-morado-700" />
        </span>
        Toca <strong>Compartir</strong> abajo en Safari
      </li>
      <li class="flex items-center gap-3">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-white">
          <Icon icon="tabler:square-plus" class="size-5 text-morado-700" />
        </span>
        Elige <strong>Agregar a inicio</strong>
      </li>
      <li class="flex items-center gap-3">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-white">
          <Icon icon="tabler:check" class="size-5 text-morado-700" />
        </span>
        Toca <strong>Agregar</strong> arriba a la derecha
      </li>
    </ol>

    <div class="mt-5 space-y-2">
      <BotonPrincipal
        v-if="!esIphone && puedeInstalarConBoton"
        icono="tabler:download"
        @click="instalar"
      >
        Instalar
      </BotonPrincipal>
      <p v-else-if="!esIphone" class="rounded-2xl bg-morado-50 p-4 text-center text-sm text-suave">
        Abre el menú <strong>⋮</strong> de tu navegador y elige <strong>Instalar app</strong> o
        <strong>Agregar a pantalla de inicio</strong>.
      </p>
      <BotonPrincipal variante="texto" @click="cerrarInstalar">Ahora no</BotonPrincipal>
    </div>
  </HojaInferior>
</template>
