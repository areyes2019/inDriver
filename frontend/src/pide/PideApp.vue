<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { useRoute } from 'vue-router'
import { esPrototipo } from './api'
import AvisoVersionNueva from './components/AvisoVersionNueva.vue'
import BarraInferior from './components/BarraInferior.vue'
import InvitacionInstalar from './components/InvitacionInstalar.vue'
import PanelPrototipo from './components/PanelPrototipo.vue'
import PermisoAvisos from './components/PermisoAvisos.vue'
import { useUiStore } from './stores/ui'

const route = useRoute()
const ui = useUiStore()
</script>

<template>
  <div class="mx-auto min-h-dvh max-w-md bg-fondo" :class="{ 'pb-24': route.meta.barra }">
    <RouterView v-slot="{ Component }">
      <Transition name="pantalla" mode="out-in">
        <component :is="Component" :key="route.path" />
      </Transition>
    </RouterView>
  </div>

  <BarraInferior v-if="route.meta.barra" />
  <InvitacionInstalar />
  <PermisoAvisos />
  <AvisoVersionNueva />
  <PanelPrototipo v-if="esPrototipo" />

  <Transition name="pantalla">
    <div
      v-if="ui.mensaje"
      class="fixed inset-x-4 bottom-28 z-50 mx-auto flex max-w-sm items-center gap-2 rounded-2xl px-4 py-3 text-sm font-medium text-white shadow-flotante"
      :class="ui.mensaje.tipo === 'error' ? 'bg-peligro' : 'bg-tinta'"
      role="status"
    >
      <Icon
        :icon="ui.mensaje.tipo === 'error' ? 'tabler:alert-triangle' : 'tabler:circle-check'"
        class="size-5 shrink-0"
        aria-hidden="true"
      />
      {{ ui.mensaje.texto }}
    </div>
  </Transition>
</template>
