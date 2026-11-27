<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue'

/** 6 casillas para el código (6.1). Acepta pegar el código completo y el autollenado de iOS. */
const props = withDefaults(defineProps<{ error?: boolean; deshabilitado?: boolean }>(), {
  error: false,
  deshabilitado: false,
})
const codigo = defineModel<string>({ required: true })
const emit = defineEmits<{ completo: [codigo: string] }>()

const LARGO = 6
const casillas = ref<HTMLInputElement[]>([])

// Una casilla vacía en medio se guarda como espacio, para que las de la derecha no se recorran.
function digitos(): string[] {
  return Array.from({ length: LARGO }, (_, i) => {
    const c = codigo.value[i]
    return c && c !== ' ' ? c : ''
  })
}

function unir(actual: string[]): string {
  return actual
    .map((d) => d || ' ')
    .join('')
    .trimEnd()
}

function poner(texto: string, desde: number): void {
  const limpio = texto.replace(/\D/g, '')
  if (!limpio) return
  const actual = digitos()
  for (let i = 0; i < limpio.length && desde + i < LARGO; i++)
    actual[desde + i] = limpio[i] as string
  codigo.value = unir(actual)
  const siguiente = Math.min(desde + limpio.length, LARGO - 1)
  nextTick(() => casillas.value[siguiente]?.focus())
  if (/^\d{6}$/.test(codigo.value)) emit('completo', codigo.value)
}

function alEscribir(evento: Event, i: number): void {
  const input = evento.target as HTMLInputElement
  const texto = input.value
  input.value = digitos()[i] ?? ''
  poner(texto.slice(-LARGO), texto.length > 1 ? 0 : i)
}

function alTecla(evento: KeyboardEvent, i: number): void {
  if (evento.key !== 'Backspace') return
  evento.preventDefault()
  const actual = digitos()
  if (actual[i]) {
    actual[i] = ''
  } else if (i > 0) {
    actual[i - 1] = ''
    casillas.value[i - 1]?.focus()
  }
  codigo.value = unir(actual)
}

function alPegar(evento: ClipboardEvent): void {
  evento.preventDefault()
  poner(evento.clipboardData?.getData('text') ?? '', 0)
}

onMounted(() => {
  if (!props.deshabilitado) casillas.value[0]?.focus()
})
</script>

<template>
  <div class="flex justify-between gap-2" role="group" aria-label="Código de 6 dígitos">
    <input
      v-for="(d, i) in digitos()"
      :key="i"
      :ref="(el) => (casillas[i] = el as HTMLInputElement)"
      :value="d"
      type="text"
      inputmode="numeric"
      :autocomplete="i === 0 ? 'one-time-code' : 'off'"
      :aria-label="`Dígito ${i + 1}`"
      :disabled="deshabilitado"
      class="aspect-square w-full min-w-0 rounded-2xl bg-white text-center font-titulo text-3xl font-bold text-tinta ring-1 outline-none focus:ring-2"
      :class="error ? 'ring-peligro focus:ring-peligro' : 'ring-linea focus:ring-morado-500'"
      @input="alEscribir($event, i)"
      @keydown="alTecla($event, i)"
      @paste="alPegar"
    />
  </div>
</template>
