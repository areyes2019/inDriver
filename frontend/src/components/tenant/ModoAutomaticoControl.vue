<script setup lang="ts">
import { computed, ref } from 'vue'
import UiToggle from '@/components/ui/UiToggle.vue'
import { useModoAutomaticoStore, type EntradaBitacora } from '@/stores/modoAutomatico'

/**
 * Botón "Automático" del pie del menú lateral (spec tenant/034).
 * Abre un diálogo con el interruptor y la bitácora de lo que hizo el sistema.
 *
 * Solo el AdminCliente mueve el interruptor (D2); el Despachador ve el estado y la bitácora.
 */
const props = withDefaults(
  defineProps<{
    slug: string
    editable: boolean
    compacto?: boolean
  }>(),
  { compacto: false },
)

const modo = useModoAutomaticoStore()

const abierto = ref(false)
const error = ref('')

const etiqueta = computed(() => {
  if (modo.pausado) return props.compacto ? 'Pausa' : 'Auto: pausado'
  if (modo.prendido) return props.compacto ? 'Auto' : 'Automático'
  return 'Manual'
})

const ACCIONES: Record<EntradaBitacora['accion'], string> = {
  REOFERTA: 'Reoferta',
  CANCELACION: 'Cancelación',
  PAUSA: 'Pausa',
  PERSONA: 'Pidió una persona',
  ATORADO: 'Pedido atorado',
}

function abrir() {
  abierto.value = true
  error.value = ''
  modo.cargarBitacora(props.slug)
}

async function alternar(valor: boolean) {
  if (!props.editable) return
  error.value = ''
  try {
    await modo.cambiar(props.slug, valor ? 'Si' : 'No')
  } catch {
    error.value = 'No se pudo cambiar el modo automático, intenta de nuevo.'
  }
}

function hora(iso: string | null): string {
  if (!iso) return ''
  return new Date(iso).toLocaleString('es-MX', {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}
</script>

<template>
  <button
    type="button"
    :aria-label="`Modo automático: ${etiqueta}`"
    title="Modo automático"
    class="flex items-center rounded-full border font-semibold uppercase tracking-wide transition-colors hover:bg-black/5"
    :class="[
      compacto ? 'gap-1 px-1.5 py-0.5 text-[10px]' : 'gap-2 px-3 py-1 text-xs',
      modo.prendido
        ? 'border-sky-400 bg-sky-100 text-sky-900'
        : modo.pausado
          ? 'border-red-300 bg-red-50 text-red-800'
          : 'border-default bg-neutral-primary text-body',
    ]"
    @click="abrir"
  >
    <span
      class="inline-block h-2 w-2 shrink-0 rounded-full"
      :class="modo.prendido ? 'bg-sky-500' : modo.pausado ? 'bg-red-500' : 'bg-gray-400'"
    />
    {{ etiqueta }}
  </button>

  <Teleport to="body">
    <div
      v-if="abierto"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      role="dialog"
      aria-modal="true"
      aria-label="Modo automático"
      @click.self="abierto = false"
    >
      <div
        class="flex max-h-[85vh] w-full max-w-lg flex-col rounded-2xl bg-white p-5 shadow-lg shadow-black/10"
      >
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-base font-semibold text-heading">Modo automático</h2>
            <p class="mt-1 text-sm text-black/70">
              El sistema vuelve a ofrecer cada 5 minutos los envíos que nadie toma y los cancela a
              los 30. Lo que hace queda en esta bitácora.
            </p>
          </div>
          <UiToggle
            :model-value="modo.prendido"
            :disabled="!editable || modo.guardando"
            aria-label="Prender o apagar el modo automático"
            @update:model-value="alternar"
          />
        </div>

        <p v-if="!editable" class="mt-3 text-xs text-black/50">
          Solo el administrador puede prender o apagar el modo automático.
        </p>
        <p
          v-if="modo.pausado"
          class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800"
          role="status"
        >
          Se pausó solo porque se cancelaron 5 envíos en una hora por falta de repartidor.
          <template v-if="editable">Revisa qué pasó y vuelve a prenderlo.</template>
        </p>
        <p v-if="error" role="alert" class="mt-3 text-sm text-red-600">{{ error }}</p>

        <h3 class="mt-5 text-sm font-semibold text-heading">Bitácora</h3>
        <div class="mt-2 min-h-0 flex-1 overflow-y-auto">
          <p v-if="modo.cargandoBitacora" class="py-4 text-sm text-black/50">Cargando...</p>
          <p v-else-if="modo.errorBitacora" class="py-4 text-sm text-red-600">
            {{ modo.errorBitacora }}
          </p>
          <p v-else-if="modo.bitacora.length === 0" class="py-4 text-sm text-black/50">
            Todavía no hay nada.
          </p>
          <ul v-else class="divide-y divide-gray-100">
            <li v-for="entrada in modo.bitacora" :key="entrada.id_bitacora" class="py-2.5">
              <div class="flex flex-wrap items-center gap-2 text-xs text-black/50">
                <span class="rounded-full bg-sky-100 px-2 py-0.5 font-semibold text-sky-900">
                  Lo hizo la IA
                </span>
                <span class="font-semibold text-heading">{{ ACCIONES[entrada.accion] }}</span>
                <span>{{ hora(entrada.created_at) }}</span>
              </div>
              <p class="mt-1 text-sm text-body">{{ entrada.detalle }}</p>
            </li>
          </ul>
        </div>

        <div class="mt-4 flex justify-end">
          <button
            type="button"
            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-heading hover:bg-black/5"
            @click="abierto = false"
          >
            Cerrar
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
