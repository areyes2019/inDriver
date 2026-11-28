<script setup lang="ts">
import { reactive, ref } from 'vue'
import axios from 'axios'
import { Icon } from '@iconify/vue'
import http, { ensureCsrfCookie } from '@/lib/http'

/**
 * Formulario de contacto de la página pública. Manda a `POST /contacto`, que guarda el mensaje y
 * avisa a contacto@motopanda.mx. `sitio_web` es un campo trampa: invisible para una persona, un bot
 * lo llena y el servidor lo descarta en silencio.
 */
const vacio = {
  nombre: '',
  empresa: '',
  telefono: '',
  email: '',
  motos: '',
  mensaje: '',
  sitio_web: '',
}
const form = reactive({ ...vacio })
const errores = ref<Record<string, string>>({})
const enviando = ref(false)
const enviado = ref(false)
const errorGeneral = ref('')

async function enviar() {
  enviando.value = true
  errores.value = {}
  errorGeneral.value = ''

  try {
    await ensureCsrfCookie()
    await http.post('/contacto', {
      ...form,
      empresa: form.empresa || null,
      motos: form.motos ? Number(form.motos) : null,
      mensaje: form.mensaje || null,
    })
    enviado.value = true
    Object.assign(form, vacio)
  } catch (err) {
    if (axios.isAxiosError(err) && err.response?.status === 422) {
      const lista = (err.response.data?.errors ?? {}) as Record<string, string[]>
      errores.value = Object.fromEntries(
        Object.entries(lista).map(([campo, mensajes]) => [campo, mensajes[0] ?? '']),
      )
    } else if (axios.isAxiosError(err) && err.response?.status === 429) {
      errorGeneral.value = 'Ya recibimos varios mensajes desde aquí. Intenta en unos minutos.'
    } else {
      errorGeneral.value =
        'No se pudo enviar. Intenta de nuevo o escríbenos a contacto@motopanda.mx.'
    }
  } finally {
    enviando.value = false
  }
}

const campo =
  'w-full rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm text-tinta placeholder:text-tinta/35 transition focus:border-panda-500 focus:ring-4 focus:ring-panda-100 focus:outline-none'
</script>

<template>
  <div v-if="enviado" class="flex flex-col items-center py-10 text-center" role="status">
    <span class="flex size-16 items-center justify-center rounded-full bg-emerald-100">
      <Icon icon="tabler:circle-check" class="size-9 text-emerald-600" />
    </span>
    <p class="mt-4 font-heading text-2xl font-bold text-tinta">¡Gracias! Ya nos llegó tu mensaje</p>
    <p class="mt-2 max-w-sm text-tinta/60">
      Te contactamos pronto para darte de alta y resolver tus dudas.
    </p>
    <button
      type="button"
      class="mt-6 text-sm font-semibold text-panda-700 hover:underline"
      @click="enviado = false"
    >
      Enviar otro mensaje
    </button>
  </div>

  <form v-else class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="enviar">
    <label class="block">
      <span class="mb-1.5 block text-sm font-semibold text-tinta">Tu nombre *</span>
      <input
        v-model="form.nombre"
        required
        autocomplete="name"
        :class="campo"
        placeholder="Laura Torres"
      />
      <span v-if="errores.nombre" class="mt-1 block text-xs text-red-600">{{
        errores.nombre
      }}</span>
    </label>
    <label class="block">
      <span class="mb-1.5 block text-sm font-semibold text-tinta">Flotilla o empresa</span>
      <input
        v-model="form.empresa"
        autocomplete="organization"
        :class="campo"
        placeholder="Mensajería Rápida"
      />
    </label>
    <label class="block">
      <span class="mb-1.5 block text-sm font-semibold text-tinta">Teléfono o WhatsApp *</span>
      <input
        v-model="form.telefono"
        type="tel"
        required
        autocomplete="tel"
        :class="campo"
        placeholder="461 123 4567"
      />
      <span v-if="errores.telefono" class="mt-1 block text-xs text-red-600">{{
        errores.telefono
      }}</span>
    </label>
    <label class="block">
      <span class="mb-1.5 block text-sm font-semibold text-tinta">Correo *</span>
      <input
        v-model="form.email"
        type="email"
        required
        autocomplete="email"
        :class="campo"
        placeholder="tu@correo.com"
      />
      <span v-if="errores.email" class="mt-1 block text-xs text-red-600">{{ errores.email }}</span>
    </label>
    <label class="block sm:col-span-2">
      <span class="mb-1.5 block text-sm font-semibold text-tinta">¿Cuántas motos tienes?</span>
      <input
        v-model="form.motos"
        type="number"
        min="1"
        inputmode="numeric"
        :class="campo"
        placeholder="Ej. 8"
      />
      <span v-if="errores.motos" class="mt-1 block text-xs text-red-600">{{ errores.motos }}</span>
    </label>
    <label class="block sm:col-span-2">
      <span class="mb-1.5 block text-sm font-semibold text-tinta">Cuéntanos de tu operación</span>
      <textarea
        v-model="form.mensaje"
        rows="4"
        :class="campo"
        placeholder="Ciudad, cuántos envíos haces al día, cómo los recibes hoy…"
      />
    </label>

    <!-- Campo trampa para bots: fuera de la vista y del orden de tabulación. -->
    <label class="absolute -left-[9999px]" aria-hidden="true">
      Sitio web
      <input v-model="form.sitio_web" tabindex="-1" autocomplete="off" />
    </label>

    <p v-if="errorGeneral" role="alert" class="text-sm text-red-600 sm:col-span-2">
      {{ errorGeneral }}
    </p>

    <div class="flex flex-col gap-3 sm:col-span-2 sm:flex-row sm:items-center">
      <button
        type="submit"
        :disabled="enviando"
        class="inline-flex items-center justify-center gap-2 rounded-xl bg-panda-700 px-7 py-3.5 font-semibold text-white shadow-lg shadow-panda-700/30 transition hover:-translate-y-0.5 hover:bg-panda-800 disabled:cursor-not-allowed disabled:opacity-60"
      >
        {{ enviando ? 'Enviando…' : 'Quiero probar Motopanda' }}
        <Icon v-if="!enviando" icon="tabler:send" class="size-4" />
      </button>
      <p class="text-xs text-tinta/50">
        Al enviar aceptas nuestro
        <a href="/privacidad/" class="underline hover:text-panda-700">aviso de privacidad</a>.
      </p>
    </div>
  </form>
</template>
