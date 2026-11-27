<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api'
import BotonPrincipal from '../components/BotonPrincipal.vue'
import CasillasCodigo from '../components/CasillasCodigo.vue'
import { useCodigo, verificacionPendiente } from '../composables/useCodigo'
import { useInstalacion } from '../composables/useInstalacion'
import { useMarcaStore } from '../stores/marca'
import { useSesionStore } from '../stores/sesion'
import { useUiStore } from '../stores/ui'

const router = useRouter()
const marca = useMarcaStore()
const sesion = useSesionStore()
const ui = useUiStore()
const { pedir } = useCodigo()
const { pedirAvisosSiToca } = useInstalacion()

const pendiente = verificacionPendiente()

const codigo = ref('')
const verificando = ref(false)
const error = ref<string | null>(null)
const segundos = ref(60)
let reloj: ReturnType<typeof setInterval> | null = null

const puedeReenviar = computed(() => segundos.value === 0)

function arrancarReloj(): void {
  segundos.value = 60
  if (reloj) clearInterval(reloj)
  reloj = setInterval(() => {
    segundos.value = Math.max(0, segundos.value - 1)
    if (segundos.value === 0 && reloj) clearInterval(reloj)
  }, 1000)
}

onMounted(() => {
  // Al recargar esta pantalla ya no hay a quién verificar: se vuelve a empezar.
  if (!pendiente) {
    router.replace({ name: 'registro', params: { slug: marca.slug } })
    return
  }
  arrancarReloj()
})
onBeforeUnmount(() => reloj && clearInterval(reloj))

async function verificar(valor = codigo.value): Promise<void> {
  if (!pendiente || !/^\d{6}$/.test(valor) || verificando.value) return
  verificando.value = true
  error.value = null
  try {
    const r = await api.verificar(marca.slug, pendiente.email, valor, pendiente.registro)
    if (!r.ok) {
      error.value = {
        CODIGO_VENCIDO: 'El código venció. Pide uno nuevo.',
        CODIGO_INCORRECTO: 'Ese código no es correcto. Revisa el correo más reciente.',
        BLOQUEADO: 'Demasiados intentos. Espera una hora e intenta de nuevo.',
        CLIENTE_INACTIVO: `Tu cuenta está desactivada. Comunícate con ${marca.nombre}.`,
      }[r.motivo]
      codigo.value = ''
      return
    }
    sesion.entrar(r.token, r.cliente)
    await router.replace({ name: 'inicio', params: { slug: marca.slug } })
    if (r.nuevo) pedirAvisosSiToca()
  } finally {
    verificando.value = false
  }
}

async function reenviar(): Promise<void> {
  if (!pendiente || !puedeReenviar.value) return
  if ((await pedir(pendiente.email, pendiente.registro, false)) === 'ok') {
    arrancarReloj()
    ui.avisar('Te mandamos un código nuevo')
  }
}
</script>

<template>
  <main v-if="pendiente" class="pt-seguro min-h-dvh px-5 pb-8">
    <button
      type="button"
      class="-ml-2 mt-2 flex min-h-11 items-center gap-1 rounded-xl px-2 text-sm font-semibold text-morado-700"
      @click="router.back()"
    >
      <Icon icon="tabler:arrow-left" class="size-5" /> Cambiar correo
    </button>

    <div class="mt-6 flex size-16 items-center justify-center rounded-3xl bg-morado-100">
      <Icon icon="tabler:mail" class="size-9 text-morado-700" />
    </div>
    <h1 class="mt-4 font-titulo text-3xl font-extrabold">Revisa tu correo</h1>
    <p class="mt-1 text-suave">
      Escribe el código de 6 dígitos que mandamos a
      <strong class="block text-tinta [overflow-wrap:anywhere]">{{ pendiente.email }}</strong>
    </p>

    <form class="mt-7" @submit.prevent="verificar()">
      <CasillasCodigo
        v-model="codigo"
        :error="Boolean(error)"
        :deshabilitado="verificando"
        @completo="verificar"
      />
      <p v-if="error" class="mt-3 text-sm text-peligro" role="alert">{{ error }}</p>

      <div class="mt-7">
        <BotonPrincipal
          tipo="submit"
          :cargando="verificando"
          :deshabilitado="!/^\d{6}$/.test(codigo)"
        >
          Continuar
        </BotonPrincipal>
      </div>
    </form>

    <p class="mt-6 flex items-start gap-2 rounded-2xl bg-amarillo-100 p-4 text-sm text-tinta">
      <Icon icon="tabler:info-circle" class="mt-0.5 size-5 shrink-0 text-morado-700" />
      <span>
        Si no lo ves en unos minutos, revisa tu carpeta de <strong>spam</strong> o
        <strong>promociones</strong>. El código sirve 10 minutos.
      </span>
    </p>

    <p class="mt-4 text-center text-sm text-suave" aria-live="polite">
      <template v-if="!puedeReenviar">Puedes pedir otro código en {{ segundos }} s</template>
      <button
        v-else
        type="button"
        class="min-h-11 font-semibold text-morado-700 underline underline-offset-2"
        @click="reenviar"
      >
        Mandarme otro código
      </button>
    </p>
  </main>
</template>
