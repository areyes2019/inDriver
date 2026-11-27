<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { useInstalacion } from '../composables/useInstalacion'
import { useUiStore } from '../stores/ui'
import BotonPrincipal from './BotonPrincipal.vue'
import HojaInferior from './HojaInferior.vue'

const ui = useUiStore()
const { activarAvisos } = useInstalacion()

async function activar(): Promise<void> {
  const respuesta = await activarAvisos()
  if (respuesta === 'granted') ui.avisar('Listo, te avisaremos cómo va tu pedido')
}
</script>

<template>
  <HojaInferior v-model="ui.hojaAvisos">
    <div class="flex flex-col items-center text-center">
      <span class="flex size-16 items-center justify-center rounded-full bg-amarillo-100">
        <Icon icon="tabler:bell-ringing" class="size-9 text-morado-700" />
      </span>
      <h2 class="mt-3 font-titulo text-2xl font-bold">¿Te avisamos?</h2>
      <p class="mt-1 text-suave">
        Te mandamos un aviso cuando tu repartidor vaya en camino y cuando entregue, aunque tengas la
        app cerrada.
      </p>
    </div>
    <div class="mt-5 space-y-2">
      <BotonPrincipal icono="tabler:bell" @click="activar">Activar avisos</BotonPrincipal>
      <BotonPrincipal variante="texto" @click="ui.hojaAvisos = false">Ahora no</BotonPrincipal>
    </div>
  </HojaInferior>
</template>
