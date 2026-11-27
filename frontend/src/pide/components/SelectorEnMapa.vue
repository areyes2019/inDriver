<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { ref } from 'vue'
import MapService from '@/services/maps/MapService'
import type { Coordenada } from '../api/tipos'
import BotonPrincipal from './BotonPrincipal.vue'
import MapaCliente from './MapaCliente.vue'

/** "Elegir en el mapa" (6.3): el cliente mueve el mapa y el pin fijo del centro marca el punto. */
const props = defineProps<{ centro: Coordenada; titulo: string; ocupado?: boolean }>()
const emit = defineEmits<{ elegir: [punto: Coordenada]; cerrar: [] }>()

const ID = 'mapa-selector'
const listo = ref(false)

function elegir(): void {
  emit('elegir', MapService.getCenter(ID) ?? props.centro)
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 mx-auto flex max-w-md flex-col bg-white">
      <header class="pt-seguro flex items-center gap-2 px-3 pb-2">
        <button
          type="button"
          class="flex size-11 items-center justify-center rounded-full active:bg-morado-50"
          aria-label="Cerrar"
          @click="emit('cerrar')"
        >
          <Icon icon="tabler:x" class="size-6" />
        </button>
        <h2 class="font-titulo text-xl font-bold">{{ titulo }}</h2>
      </header>

      <div class="relative flex-1">
        <MapaCliente :id="ID" :centro="centro" :zoom="16" @listo="listo = $event" />
        <div
          class="pointer-events-none absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-full"
          aria-hidden="true"
        >
          <Icon icon="tabler:map-pin" class="size-12 text-morado-700 drop-shadow-lg" />
        </div>
        <p
          class="absolute inset-x-4 top-4 rounded-2xl bg-white/95 p-3 text-center text-sm text-tinta shadow-tarjeta"
        >
          Mueve el mapa hasta que el pin quede en el lugar exacto
        </p>
      </div>

      <div class="pb-seguro px-5 pt-4">
        <BotonPrincipal
          :cargando="ocupado"
          :deshabilitado="!listo"
          icono="tabler:check"
          @click="elegir"
        >
          Usar este punto
        </BotonPrincipal>
      </div>
    </div>
  </Teleport>
</template>
