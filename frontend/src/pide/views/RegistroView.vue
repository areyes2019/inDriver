<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BannerFlotilla from '../components/BannerFlotilla.vue'
import BotonPrincipal from '../components/BotonPrincipal.vue'
import CabeceraMarca from '../components/CabeceraMarca.vue'
import CampoTexto from '../components/CampoTexto.vue'
import { esCorreoValido, normalizarCorreo, useCodigo } from '../composables/useCodigo'
import { useMarcaStore } from '../stores/marca'
import { esTelefonoValido, formatoTelefono, soloDiezDigitos } from '../lib/telefono'

const marca = useMarcaStore()
const router = useRouter()
const route = useRoute()
const { pedir } = useCodigo()

const nombre = ref('')
const telefono = ref('')
// RN-15: si viene de "No encontramos tu cuenta", el correo ya está escrito.
const correo = ref(String(route.query.email ?? ''))
const enviando = ref(false)
const intento = ref(false)
const correoRechazado = ref(false)
watch(correo, () => (correoRechazado.value = false))

const telefonoVisible = computed({
  get: () => formatoTelefono(telefono.value),
  set: (v: string) => (telefono.value = soloDiezDigitos(v)),
})

const errorNombre = computed(() =>
  intento.value && nombre.value.trim().length < 3 ? 'Escribe tu nombre completo' : null,
)
const errorTelefono = computed(() =>
  intento.value && !esTelefonoValido(telefono.value)
    ? 'Escribe los 10 dígitos de tu celular'
    : null,
)
const errorCorreo = computed(() => {
  if (correoRechazado.value) return 'Revisa tu correo, parece que tiene un error'
  return intento.value && !esCorreoValido(correo.value) ? 'Escribe un correo válido' : null
})

async function enviar(): Promise<void> {
  intento.value = true
  if (errorNombre.value || errorTelefono.value || errorCorreo.value) return
  enviando.value = true
  try {
    const r = await pedir(normalizarCorreo(correo.value), {
      nombre: nombre.value.trim(),
      telefono: telefono.value,
    })
    correoRechazado.value = r === 'correo-invalido'
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <main>
    <CabeceraMarca>
      <BannerFlotilla :url="marca.marca?.bannerUrl ?? null" :nombre="marca.nombre" />
    </CabeceraMarca>

    <form
      class="relative -mt-6 rounded-t-[2rem] bg-fondo px-5 pt-7 pb-8"
      novalidate
      @submit.prevent="enviar"
    >
      <h1 class="font-titulo text-3xl font-extrabold text-tinta">Crea tu cuenta</h1>
      <p class="mt-1 text-suave">Pide tus mandados en un minuto. Sin contraseñas.</p>

      <div class="mt-6 space-y-4">
        <CampoTexto
          v-model="nombre"
          etiqueta="Tu nombre"
          placeholder="Nombre y apellido"
          autocompletar="name"
          :error="errorNombre"
        />
        <CampoTexto
          v-model="telefonoVisible"
          etiqueta="Tu celular"
          tipo="tel"
          modo-teclado="tel"
          prefijo="+52"
          placeholder="461 123 4567"
          autocompletar="tel-national"
          :error="errorTelefono"
          ayuda="Para que tu repartidor pueda llamarte"
        />
        <CampoTexto
          v-model="correo"
          etiqueta="Tu correo"
          tipo="email"
          modo-teclado="email"
          placeholder="tucorreo@ejemplo.com"
          autocompletar="email"
          :error="errorCorreo"
          ayuda="Te mandaremos un código para entrar"
        />
      </div>

      <div class="mt-7">
        <BotonPrincipal tipo="submit" :cargando="enviando" icono="tabler:mail">
          Enviarme código
        </BotonPrincipal>
      </div>

      <p class="mt-6 text-center text-sm text-suave">
        ¿Ya tienes cuenta?
        <button
          type="button"
          class="min-h-11 font-semibold text-morado-700 underline-offset-2 hover:underline"
          @click="router.push({ name: 'entrar', params: { slug: marca.slug } })"
        >
          Entrar
        </button>
      </p>
    </form>
  </main>
</template>
