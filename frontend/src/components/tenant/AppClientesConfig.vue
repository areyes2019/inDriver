<script setup lang="ts">
import { computed, ref } from 'vue'
import http from '@/lib/http'
import UiAlert from '@/components/ui/UiAlert.vue'
import UiToggle from '@/components/ui/UiToggle.vue'

/**
 * Interruptor de la app de clientes (spec tenant/035, D17). Solo AdminCliente. Apagada, la app de
 * esta flotilla muestra "Muy pronto" y no deja registrarse ni entrar.
 */
const props = defineProps<{ slug: string }>()
const prendida = defineModel<boolean>({ required: true })

const guardando = ref(false)
const error = ref('')
const copiado = ref(false)

const enlace = computed(() => `${window.location.origin}/pide/${props.slug}/`)

async function cambiar(valor: boolean): Promise<void> {
  const anterior = prendida.value
  prendida.value = valor
  guardando.value = true
  error.value = ''
  try {
    await http.put(`/t/${props.slug}/configuracion/app-clientes`, {
      app_clientes: valor ? 'Si' : 'No',
    })
  } catch {
    prendida.value = anterior
    error.value = 'No se pudo cambiar. Intenta de nuevo.'
  } finally {
    guardando.value = false
  }
}

async function copiar(): Promise<void> {
  try {
    await navigator.clipboard.writeText(enlace.value)
    copiado.value = true
    setTimeout(() => (copiado.value = false), 2000)
  } catch {
    // Sin permiso de portapapeles: el enlace sigue visible para copiarlo a mano.
  }
}
</script>

<template>
  <div class="max-w-2xl space-y-5">
    <div class="flex items-start justify-between gap-6 rounded-lg border border-gray-200 p-4">
      <div>
        <p class="font-semibold text-heading">Recibir pedidos desde la app</p>
        <p class="mt-1 text-sm text-body">
          Tus clientes se registran con su correo y piden sus envíos desde el celular. Los pedidos
          llegan al Panel como cualquier otro.
        </p>
      </div>
      <UiToggle
        :model-value="prendida"
        :disabled="guardando"
        aria-label="Recibir pedidos desde la app"
        @update:model-value="cambiar"
      />
    </div>

    <UiAlert v-if="error" variant="error">{{ error }}</UiAlert>

    <div class="rounded-lg border border-gray-200 p-4">
      <p class="font-semibold text-heading">Enlace de tu app</p>
      <p class="mt-1 text-sm text-body">
        Compártelo con tus clientes. Mientras la app esté apagada, verán "Muy pronto".
      </p>
      <div class="mt-3 flex flex-wrap items-center gap-2">
        <code class="rounded bg-gray-50 px-3 py-2 text-sm text-heading">{{ enlace }}</code>
        <button
          type="button"
          class="rounded-lg border border-accent px-3 py-1.5 text-sm font-semibold text-accent transition-colors hover:bg-accent hover:text-white"
          @click="copiar"
        >
          {{ copiado ? 'Copiado' : 'Copiar' }}
        </button>
      </div>
    </div>
  </div>
</template>
