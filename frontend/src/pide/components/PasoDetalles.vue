<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { computed } from 'vue'
import { useAsistenteStore } from '../stores/asistente'
import { useMarcaStore } from '../stores/marca'
import CampoTexto from './CampoTexto.vue'
import TarjetasQuienPaga from './TarjetasQuienPaga.vue'

/** Paso 3 (6.3): quién paga, importe del producto y cuándo. Solo los campos que aplican. */
const asistente = useAsistenteStore()
const marca = useMarcaStore()
const b = computed(() => asistente.b)

const abierto = computed(() => marca.marca?.abiertoAhora ?? true)
if (!abierto.value) asistente.b.loAntesPosible = false

const hoy = new Date()
const iso = (d: Date) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const fechaMinima = iso(hoy)
const fechaMaxima = iso(new Date(hoy.getTime() + 7 * 86_400_000))

/** RN-28: al menos 30 minutos en el futuro. */
const errorHora = computed(() => {
  const d = b.value
  if (d.loAntesPosible || !d.fecha || !d.hora) return null
  const elegida = new Date(`${d.fecha}T${d.hora}:00`).getTime()
  return elegida < Date.now() + 30 * 60_000
    ? 'Elige una hora al menos 30 minutos después de ahora'
    : null
})
</script>

<template>
  <div class="space-y-7">
    <section>
      <h2 class="mb-3 font-titulo text-xl font-bold">¿Quién paga?</h2>
      <TarjetasQuienPaga v-model="asistente.b.modalidad" />
      <p class="mt-3 flex items-center gap-2 text-sm text-suave">
        <Icon icon="tabler:cash" class="size-5 text-exito" /> Pago en efectivo al repartidor
      </p>
    </section>

    <section v-if="b.modalidad === 'RECEPTOR_PAGA_ENVIO_PRODUCTOS'">
      <CampoTexto
        v-model="asistente.b.importeProducto"
        etiqueta="¿Cuánto cuesta el producto?"
        modo-teclado="decimal"
        prefijo="$"
        placeholder="0"
        ayuda="El repartidor lo cobra al entregar, junto con el envío"
      />
    </section>

    <section>
      <h2 class="mb-3 font-titulo text-xl font-bold">¿Cuándo?</h2>
      <div class="grid grid-cols-2 gap-2.5" role="radiogroup" aria-label="¿Cuándo?">
        <button
          type="button"
          role="radio"
          :aria-checked="b.loAntesPosible"
          :disabled="!abierto"
          class="flex min-h-16 flex-col items-center justify-center rounded-2xl bg-white font-semibold ring-1 disabled:opacity-45"
          :class="
            b.loAntesPosible ? 'text-morado-700 ring-2 ring-morado-600' : 'text-tinta ring-linea'
          "
          @click="asistente.b.loAntesPosible = true"
        >
          <Icon icon="tabler:motorbike" class="size-6" />
          Lo antes posible
        </button>
        <button
          type="button"
          role="radio"
          :aria-checked="!b.loAntesPosible"
          class="flex min-h-16 flex-col items-center justify-center rounded-2xl bg-white font-semibold ring-1"
          :class="
            !b.loAntesPosible ? 'text-morado-700 ring-2 ring-morado-600' : 'text-tinta ring-linea'
          "
          @click="asistente.b.loAntesPosible = false"
        >
          <Icon icon="tabler:calendar" class="size-6" />
          Programar
        </button>
      </div>
      <p v-if="!abierto" class="mt-2 text-sm text-suave">
        Ahora estamos cerrados ({{ marca.marca?.horario }}). Puedes programarlo.
      </p>

      <div v-if="!b.loAntesPosible" class="mt-4 grid grid-cols-2 gap-3">
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold">Día</span>
          <input
            v-model="asistente.b.fecha"
            type="date"
            :min="fechaMinima"
            :max="fechaMaxima"
            class="min-h-14 w-full rounded-2xl bg-white px-3 text-base ring-1 ring-linea outline-none focus:ring-2 focus:ring-morado-500"
          />
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold">Hora</span>
          <input
            v-model="asistente.b.hora"
            type="time"
            step="900"
            class="min-h-14 w-full rounded-2xl bg-white px-3 text-base ring-1 ring-linea outline-none focus:ring-2 focus:ring-morado-500"
          />
        </label>
        <p v-if="errorHora" class="col-span-2 text-sm text-peligro" role="alert">{{ errorHora }}</p>
      </div>
    </section>
  </div>
</template>
