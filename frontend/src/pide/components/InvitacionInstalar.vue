<script setup lang="ts">
import { computed, ref } from 'vue'
import { useInstalacion } from '../composables/useInstalacion'
import { useMarcaStore } from '../stores/marca'
import { useUiStore } from '../stores/ui'
import BotonPrincipal from './BotonPrincipal.vue'
import GuiaInstalacion from './GuiaInstalacion.vue'
import HojaInferior from './HojaInferior.vue'
import LogoFlotilla from './LogoFlotilla.vue'

/**
 * Recordatorio único de instalar, para quien eligió "Continuar en el navegador" en la bienvenida
 * (spec tenant/035, D7). También se abre desde "Mi cuenta → Instalar la app".
 */
const ui = useUiStore()
const marca = useMarcaStore()
const { esIphone, puedeInstalarConBoton, instalar, cerrarInstalar } = useInstalacion()
const mostrarGuia = ref(false)

const abierta = computed({
  get: () => ui.hojaInstalar,
  set: (v) => (v ? (ui.hojaInstalar = true) : cerrarInstalar()),
})

async function tocarInstalar(): Promise<void> {
  const resultado = await instalar()
  if (resultado === 'sin-boton') mostrarGuia.value = true
  else cerrarInstalar()
}
</script>

<template>
  <HojaInferior v-model="abierta">
    <div class="flex flex-col items-center text-center">
      <LogoFlotilla :url="marca.marca?.logoUrl ?? null" :nombre="marca.nombre" :tamano="72" />
      <h2 class="mt-3 font-titulo text-2xl font-bold">Agrega {{ marca.nombre }} a tu inicio</h2>
      <p class="mt-1 text-suave">Ábrela con un toque, como cualquier app, sin descargar nada.</p>
    </div>

    <div v-if="esIphone || mostrarGuia || !puedeInstalarConBoton" class="mt-5">
      <GuiaInstalacion :es-iphone="esIphone" :nombre="marca.nombre" />
    </div>

    <div class="mt-5 space-y-2">
      <BotonPrincipal
        v-if="!esIphone && puedeInstalarConBoton && !mostrarGuia"
        variante="amarillo"
        icono="tabler:download"
        @click="tocarInstalar"
      >
        Instalar
      </BotonPrincipal>
      <BotonPrincipal variante="texto" @click="cerrarInstalar">Ahora no</BotonPrincipal>
    </div>
  </HojaInferior>
</template>
