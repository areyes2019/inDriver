<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { computed, ref } from 'vue'
import BannerFlotilla from '../components/BannerFlotilla.vue'
import BotonPrincipal from '../components/BotonPrincipal.vue'
import CabeceraMarca from '../components/CabeceraMarca.vue'
import GuiaInstalacion from '../components/GuiaInstalacion.vue'
import { useInstalacion } from '../composables/useInstalacion'
import { useMarcaStore } from '../stores/marca'

/**
 * Lo único que la app muestra en el navegador (spec tenant/035, D7: instalación obligatoria). El
 * cliente instala y todo lo demás —registro, entrada, pedidos— lo hace desde la app instalada.
 */
const marca = useMarcaStore()
const { esIphone, puedeInstalarConBoton, recienInstalada, instalar } = useInstalacion()

const mostrarGuia = ref(false)
const instalando = ref(false)
const aceptada = ref(false)
const lista = computed(() => aceptada.value || recienInstalada.value)

async function tocarInstalar(): Promise<void> {
  if (esIphone) {
    mostrarGuia.value = true
    return
  }
  instalando.value = true
  try {
    const resultado = await instalar()
    if (resultado === 'aceptada') aceptada.value = true
    else if (resultado === 'sin-boton') mostrarGuia.value = true
  } finally {
    instalando.value = false
  }
}
</script>

<template>
  <main class="min-h-dvh">
    <CabeceraMarca>
      <BannerFlotilla :url="marca.marca?.bannerUrl ?? null" :nombre="marca.nombre" />
    </CabeceraMarca>

    <section class="relative -mt-6 rounded-t-[2rem] bg-fondo px-5 pt-8 pb-10 text-center">
      <template v-if="lista">
        <span class="mx-auto flex size-20 items-center justify-center rounded-full bg-green-100">
          <Icon icon="tabler:circle-check" class="size-12 text-exito" />
        </span>
        <h1 class="mt-5 font-titulo text-3xl font-extrabold">¡Listo!</h1>
        <p class="mt-2 text-suave">
          Busca <strong class="text-tinta">{{ marca.nombre }}</strong> en tu pantalla de inicio y
          ábrela desde ahí para crear tu cuenta.
        </p>
      </template>

      <template v-else>
        <h1 class="font-titulo text-3xl font-extrabold text-tinta">
          Instala {{ marca.nombre }} en tu celular
        </h1>
        <p class="mt-2 text-suave">
          Pide tus mandados con un toque desde tu pantalla de inicio. Es gratis y no ocupa espacio:
          no se descarga de ninguna tienda.
        </p>

        <div v-if="mostrarGuia" class="mt-6">
          <p class="mb-3 text-sm font-semibold text-tinta">Así se instala:</p>
          <GuiaInstalacion :es-iphone="esIphone" :nombre="marca.nombre" />
        </div>

        <div v-else class="mt-8">
          <BotonPrincipal
            variante="amarillo"
            icono="tabler:download"
            :cargando="instalando"
            class="min-h-16 text-lg"
            @click="tocarInstalar"
          >
            Instalar
          </BotonPrincipal>
          <p v-if="!esIphone && !puedeInstalarConBoton" class="mt-2 text-xs text-suave">
            Te mostraremos cómo hacerlo en tu celular.
          </p>
        </div>
        <p class="mt-8 text-sm text-suave">Para pedir tus mandados necesitas la app instalada.</p>
      </template>
    </section>
  </main>
</template>
