<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { useRouter } from 'vue-router'

const props = defineProps<{ titulo: string; volverA?: string }>()
const emit = defineEmits<{ volver: [] }>()
const router = useRouter()

function volver(): void {
  emit('volver')
  if (props.volverA) router.push(props.volverA)
}
</script>

<template>
  <header
    class="pt-seguro sticky top-0 z-20 flex items-center gap-2 bg-fondo/90 px-3 pb-2 backdrop-blur"
  >
    <button
      type="button"
      class="flex size-11 items-center justify-center rounded-full text-tinta active:bg-morado-50"
      aria-label="Volver"
      @click="volver"
    >
      <Icon icon="tabler:arrow-left" class="size-6" />
    </button>
    <h1 class="truncate font-titulo text-xl font-bold text-tinta">{{ titulo }}</h1>
    <div class="ml-auto"><slot name="accion" /></div>
  </header>
</template>
