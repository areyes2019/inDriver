<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import BotonPrincipal from '../components/BotonPrincipal.vue'
import CabeceraMarca from '../components/CabeceraMarca.vue'
import CampoTexto from '../components/CampoTexto.vue'
import { esCorreoValido, normalizarCorreo, useCodigo } from '../composables/useCodigo'
import { useMarcaStore } from '../stores/marca'

const marca = useMarcaStore()
const router = useRouter()
const { pedir } = useCodigo()

const correo = ref('')
const enviando = ref(false)
const intento = ref(false)
const noEncontrado = ref(false)
const correoRechazado = ref(false)

const errorCorreo = computed(() => {
  if (correoRechazado.value) return 'Revisa tu correo, parece que tiene un error'
  return intento.value && !esCorreoValido(correo.value) ? 'Escribe un correo válido' : null
})
watch(correo, () => {
  noEncontrado.value = false
  correoRechazado.value = false
})

async function enviar(): Promise<void> {
  intento.value = true
  if (errorCorreo.value) return
  enviando.value = true
  try {
    const r = await pedir(normalizarCorreo(correo.value), null)
    noEncontrado.value = r === 'no-encontrado'
    correoRechazado.value = r === 'correo-invalido'
  } finally {
    enviando.value = false
  }
}

/** RN-15: se ofrece registrarse con el mismo correo, sin volver a escribirlo. */
function registrarme(): void {
  router.push({
    name: 'registro',
    params: { slug: marca.slug },
    query: { email: normalizarCorreo(correo.value) },
  })
}
</script>

<template>
  <main class="min-h-dvh">
    <CabeceraMarca />
    <form
      class="relative -mt-4 rounded-t-[2rem] bg-fondo px-5 pt-7 pb-8"
      novalidate
      @submit.prevent="enviar"
    >
      <button
        type="button"
        class="-ml-2 mb-3 flex min-h-11 items-center gap-1 rounded-xl px-2 text-sm font-semibold text-morado-700"
        @click="router.push({ name: 'registro', params: { slug: marca.slug } })"
      >
        <Icon icon="tabler:arrow-left" class="size-5" /> Volver
      </button>

      <h1 class="font-titulo text-3xl font-extrabold">Qué gusto verte</h1>
      <p class="mt-1 text-suave">Escribe tu correo y te mandamos un código para entrar.</p>

      <div class="mt-6">
        <CampoTexto
          v-model="correo"
          etiqueta="Tu correo"
          tipo="email"
          modo-teclado="email"
          placeholder="tucorreo@ejemplo.com"
          autocompletar="email"
          :error="errorCorreo"
        />
      </div>

      <div
        v-if="noEncontrado"
        class="mt-4 rounded-2xl bg-amarillo-100 p-4 text-sm text-tinta"
        role="alert"
      >
        <p class="font-semibold">No encontramos tu cuenta</p>
        <p class="mt-0.5 text-suave">Puedes crearla ahora con este mismo correo.</p>
        <button
          type="button"
          class="mt-2 min-h-11 font-semibold text-morado-700 underline underline-offset-2"
          @click="registrarme"
        >
          Crear mi cuenta
        </button>
      </div>

      <div class="mt-7">
        <BotonPrincipal tipo="submit" :cargando="enviando" icono="tabler:mail">
          Enviarme código
        </BotonPrincipal>
      </div>
    </form>
  </main>
</template>
