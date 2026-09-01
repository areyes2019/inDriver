<script setup lang="ts">
import { Icon } from '@iconify/vue'

export type ModalidadPago =
  'RECEPTOR_PAGA_ENVIO' | 'REMITENTE_PAGA_ENVIO' | 'RECEPTOR_PAGA_ENVIO_PRODUCTOS'

defineProps<{
  modelValue: ModalidadPago
}>()

const emit = defineEmits<{
  'update:modelValue': [value: ModalidadPago]
}>()

const opciones: Array<{
  valor: ModalidadPago
  texto: string
  iconos: [string, string]
  activo: string
  inactivo: string
}> = [
  {
    valor: 'RECEPTOR_PAGA_ENVIO',
    texto: 'Receptor paga envío',
    iconos: ['flat-color-icons:package', 'flat-color-icons:paid'],
    activo: 'border-transparent bg-info-bg text-info-text ring-2 ring-info-text ring-offset-1',
    inactivo: 'border-gray-300 bg-white text-heading hover:bg-slate-50',
  },
  {
    valor: 'REMITENTE_PAGA_ENVIO',
    texto: 'Remitente paga envío',
    iconos: ['flat-color-icons:paid', 'flat-color-icons:package'],
    activo:
      'border-transparent bg-success-bg text-success-text ring-2 ring-success-text ring-offset-1',
    inactivo: 'border-gray-300 bg-white text-heading hover:bg-slate-50',
  },
  {
    valor: 'RECEPTOR_PAGA_ENVIO_PRODUCTOS',
    texto: 'Receptor paga envío + producto',
    iconos: ['flat-color-icons:shop', 'flat-color-icons:paid'],
    activo:
      'border-transparent bg-purple-bg text-purple-text ring-2 ring-purple-text ring-offset-1',
    inactivo: 'border-gray-300 bg-white text-heading hover:bg-slate-50',
  },
]

function onKeydown(event: KeyboardEvent, valor: ModalidadPago) {
  if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return
  event.preventDefault()

  const indiceActual = opciones.findIndex((o) => o.valor === valor)
  const paso = event.key === 'ArrowRight' ? 1 : -1
  const siguiente = opciones[(indiceActual + paso + opciones.length) % opciones.length]

  emit('update:modelValue', siguiente.valor)
  ;(event.currentTarget as HTMLElement | null)
    ?.closest('[role="radiogroup"]')
    ?.querySelectorAll<HTMLElement>('[role="radio"]')
    [(indiceActual + paso + opciones.length) % opciones.length]?.focus()
}
</script>

<template>
  <div
    role="radiogroup"
    aria-label="Modalidad de pago"
    class="grid grid-cols-1 gap-3 sm:grid-cols-3"
  >
    <button
      v-for="opcion in opciones"
      :key="opcion.valor"
      type="button"
      role="radio"
      :aria-checked="modelValue === opcion.valor"
      :tabindex="modelValue === opcion.valor ? 0 : -1"
      class="flex items-center gap-2 rounded-lg border px-3 py-2.5 text-left text-sm font-semibold transition-colors focus:outline-none"
      :class="modelValue === opcion.valor ? opcion.activo : opcion.inactivo"
      @click="emit('update:modelValue', opcion.valor)"
      @keydown="onKeydown($event, opcion.valor)"
    >
      <span class="flex shrink-0 items-center">
        <Icon :icon="opcion.iconos[0]" width="22" height="22" aria-hidden="true" />
        <Icon :icon="opcion.iconos[1]" width="22" height="22" class="-ml-2" aria-hidden="true" />
      </span>
      <span>{{ opcion.texto }}</span>
    </button>
  </div>
</template>
