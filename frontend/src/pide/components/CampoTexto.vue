<script setup lang="ts">
import { useId } from 'vue'

withDefaults(
  defineProps<{
    etiqueta: string
    tipo?: string
    modoTeclado?: 'text' | 'tel' | 'numeric' | 'decimal' | 'email'
    placeholder?: string
    prefijo?: string
    error?: string | null
    ayuda?: string
    autocompletar?: string
    maximo?: number
  }>(),
  {
    tipo: 'text',
    modoTeclado: 'text',
    placeholder: '',
    prefijo: undefined,
    error: null,
    ayuda: undefined,
    autocompletar: 'off',
    maximo: undefined,
  },
)

const valor = defineModel<string>({ required: true })
const id = useId()
</script>

<template>
  <div>
    <label :for="id" class="mb-1.5 block text-sm font-semibold text-tinta">{{ etiqueta }}</label>
    <div
      class="flex items-center rounded-2xl bg-white ring-1 transition-shadow focus-within:ring-2"
      :class="
        error ? 'ring-peligro focus-within:ring-peligro' : 'ring-linea focus-within:ring-morado-500'
      "
    >
      <span v-if="prefijo" class="pl-4 text-base font-medium text-suave">{{ prefijo }}</span>
      <input
        :id="id"
        v-model="valor"
        :type="tipo"
        :inputmode="modoTeclado"
        :placeholder="placeholder"
        :autocomplete="autocompletar"
        :maxlength="maximo"
        :aria-invalid="Boolean(error)"
        :aria-describedby="error || ayuda ? `${id}-nota` : undefined"
        class="min-h-14 w-full rounded-2xl bg-transparent px-4 text-base text-tinta outline-none placeholder:text-suave/60"
      />
    </div>
    <p
      v-if="error || ayuda"
      :id="`${id}-nota`"
      class="mt-1.5 text-sm"
      :class="error ? 'text-peligro' : 'text-suave'"
    >
      {{ error || ayuda }}
    </p>
  </div>
</template>
