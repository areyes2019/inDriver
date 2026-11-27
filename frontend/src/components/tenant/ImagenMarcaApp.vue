<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'
import http from '@/lib/http'

/**
 * Una imagen de la marca de la app de clientes (spec tenant/035, RN-60 y RN-61): su vista previa tal
 * como se ve en el teléfono, las especificaciones y los botones para cambiarla o quitarla. Revisa
 * tipo, peso y medidas en el navegador para avisar al instante; el servidor vuelve a revisarlo todo.
 */
const props = defineProps<{
  slug: string
  tipo: 'logo' | 'banner'
  titulo: string
  descripcion: string
  especificaciones: string[]
  /** Formatos aceptados, para el selector de archivos y la revisión previa. */
  formatos: string[]
  pesoMaxMb: number
  /** Devuelve un mensaje de error si las medidas no sirven, o `null` si están bien. */
  revisarMedidas: (ancho: number, alto: number) => string | null
  editable: boolean
}>()

const url = defineModel<string | null>({ required: true })

const input = ref<HTMLInputElement>()
const subiendo = ref(false)
const error = ref('')
const exito = ref('')

function medidas(archivo: File): Promise<{ ancho: number; alto: number }> {
  return new Promise((resolve, reject) => {
    const objeto = URL.createObjectURL(archivo)
    const imagen = new Image()
    imagen.onload = () => {
      resolve({ ancho: imagen.naturalWidth, alto: imagen.naturalHeight })
      URL.revokeObjectURL(objeto)
    }
    imagen.onerror = () => {
      reject(new Error('imagen'))
      URL.revokeObjectURL(objeto)
    }
    imagen.src = objeto
  })
}

async function revisar(archivo: File): Promise<string | null> {
  if (!props.formatos.includes(archivo.type)) {
    return 'Ese formato no se acepta. Revisa las especificaciones.'
  }
  if (archivo.size > props.pesoMaxMb * 1024 * 1024) {
    return `La imagen pesa más de ${props.pesoMaxMb} MB.`
  }
  try {
    const { ancho, alto } = await medidas(archivo)
    return props.revisarMedidas(ancho, alto)
  } catch {
    return 'No se pudo leer la imagen.'
  }
}

async function onArchivo(evento: Event) {
  const archivo = (evento.target as HTMLInputElement).files?.[0]
  ;(evento.target as HTMLInputElement).value = ''
  if (!archivo) return

  error.value = ''
  exito.value = ''

  const problema = await revisar(archivo)
  if (problema) {
    error.value = problema
    return
  }

  const datos = new FormData()
  datos.append(props.tipo, archivo)
  subiendo.value = true

  try {
    const { data } = await http.post(
      `/t/${props.slug}/configuracion/app-clientes/${props.tipo}`,
      datos,
    )
    url.value = data[`${props.tipo}_url`] ?? null
    exito.value = 'Listo. Tus clientes ya lo ven en la app.'
  } catch (err) {
    const errores = axios.isAxiosError(err) ? err.response?.data?.errors?.[props.tipo] : undefined
    error.value = errores?.[0] ?? 'No se pudo subir la imagen. Intenta de nuevo.'
  } finally {
    subiendo.value = false
  }
}

async function onQuitar() {
  error.value = ''
  exito.value = ''
  subiendo.value = true

  try {
    await http.delete(`/t/${props.slug}/configuracion/app-clientes/${props.tipo}`)
    url.value = null
    exito.value = 'Se quitó. La app vuelve a usar la de MotoPanda.'
  } catch {
    error.value = 'No se pudo quitar. Intenta de nuevo.'
  } finally {
    subiendo.value = false
  }
}
</script>

<template>
  <div class="rounded-lg border border-gray-200 p-4">
    <p class="font-semibold text-heading">{{ titulo }}</p>
    <p class="mt-1 text-sm text-body">{{ descripcion }}</p>

    <div class="mt-4 flex flex-col gap-5 sm:flex-row sm:items-start">
      <!-- Vista previa con la forma en que la pinta la app. -->
      <div class="shrink-0">
        <template v-if="tipo === 'logo'">
          <div
            class="flex size-24 items-center justify-center overflow-hidden rounded-full border border-gray-200 bg-white"
          >
            <img
              :src="url ?? '/pide/iconos/icono-512.png'"
              :alt="url ? 'Logo de tu flotilla' : 'Logo de MotoPanda'"
              class="size-full object-cover"
            />
          </div>
        </template>
        <template v-else>
          <div
            class="flex h-[8.5rem] w-full max-w-[19rem] items-center justify-center overflow-hidden rounded-lg bg-gray-100 sm:w-[19rem]"
          >
            <img v-if="url" :src="url" alt="Banner de tu flotilla" class="size-full object-cover" />
            <span v-else class="px-4 text-center text-xs text-black/50">
              Sin banner propio: se usa el de MotoPanda
            </span>
          </div>
        </template>
        <p class="mt-2 text-xs text-black/50">
          {{ url ? 'Así se ve en la app' : 'Se usa el de MotoPanda' }}
        </p>
      </div>

      <div class="min-w-0 flex-1">
        <p class="text-xs font-semibold tracking-wide text-black/50 uppercase">Especificaciones</p>
        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-body">
          <li v-for="linea in especificaciones" :key="linea">{{ linea }}</li>
        </ul>

        <div v-if="editable" class="mt-4 flex flex-wrap items-center gap-2">
          <input
            ref="input"
            type="file"
            :accept="formatos.join(',')"
            class="hidden"
            @change="onArchivo"
          />
          <button
            type="button"
            :disabled="subiendo"
            class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60"
            @click="input?.click()"
          >
            {{ subiendo ? 'Subiendo...' : url ? 'Cambiar' : 'Subir' }}
          </button>
          <button
            v-if="url"
            type="button"
            :disabled="subiendo"
            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-heading transition-colors hover:border-red-400 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-60"
            @click="onQuitar"
          >
            Quitar
          </button>
        </div>

        <p v-if="error" role="alert" class="mt-3 text-sm text-red-600">{{ error }}</p>
        <p v-if="exito" class="mt-3 text-sm text-green-600">{{ exito }}</p>
      </div>
    </div>
  </div>
</template>
