<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import BannerFlotilla from '../components/BannerFlotilla.vue'
import BotonPrincipal from '../components/BotonPrincipal.vue'
import CabeceraMarca from '../components/CabeceraMarca.vue'
import GuiaInstalacion from '../components/GuiaInstalacion.vue'
import { useInstalacion } from '../composables/useInstalacion'
import { useMarcaStore } from '../stores/marca'

/**
 * Primera pantalla al abrir la liga sin la app instalada (spec tenant/035, D7 corregida): el cliente
 * instala y después se registra desde el ícono. En iPhone importa además porque la app instalada no
 * comparte la sesión con Safari: registrarse antes obligaría a pedir otro código.
 */
const marca = useMarcaStore()
const router = useRouter()
const { esIphone, puedeInstalarConBoton, recienInstalada, instalar, continuarEnNavegador } =
  useInstalacion()

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

function seguirEnNavegador(): void {
  continuarEnNavegador()
  router.replace({ name: 'registro', params: { slug: marca.slug } })
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
      </template>

      <button
        type="button"
        class="mt-8 min-h-11 text-sm font-semibold text-suave underline underline-offset-2"
        @click="seguirEnNavegador"
      >
        Continuar en el navegador
      </button>
    </section>
  </main>
</template>
