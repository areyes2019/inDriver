<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import type { Direccion, Punto, TipoServicio } from '../api/tipos'
import BarraPasos from '../components/BarraPasos.vue'
import BotonPrincipal from '../components/BotonPrincipal.vue'
import BuscadorDireccion from '../components/BuscadorDireccion.vue'
import CampoTexto from '../components/CampoTexto.vue'
import PasoConfirmacion from '../components/PasoConfirmacion.vue'
import PasoDetalles from '../components/PasoDetalles.vue'
import { useInstalacion } from '../composables/useInstalacion'
import { formatoTelefono, soloDiezDigitos } from '../lib/telefono'
import { pesos } from '../lib/formato'
import { useAsistenteStore, type Paso } from '../stores/asistente'
import { useMarcaStore } from '../stores/marca'
import { useUiStore } from '../stores/ui'

const route = useRoute()
const router = useRouter()
const marca = useMarcaStore()
const ui = useUiStore()
const asistente = useAsistenteStore()
const { ofrecerSiToca } = useInstalacion()

const tipo: TipoServicio = route.params.tipo === 'recibir' ? 'RECIBIR' : 'ENVIAR'
const favoritas = ref<Direccion[]>([])
const enviando = ref(false)
const listo = ref(false)

const b = computed(() => asistente.b)
const paso = computed(() => asistente.b.paso)
const titulo = tipo === 'ENVIAR' ? 'Enviar un paquete' : 'Recibir un paquete'

/**
 * Campo de teléfono que muestra espacios pero guarda 10 dígitos. Lee el contacto en cada acceso:
 * `iniciar()` reemplaza el borrador completo y una referencia guardada quedaría apuntando al viejo.
 */
function telefonoDe(contacto: () => { telefono: string }) {
  return computed({
    get: () => formatoTelefono(contacto().telefono),
    set: (v: string) => (contacto().telefono = soloDiezDigitos(v)),
  })
}
const telContacto = telefonoDe(() => asistente.b.contactoRecogida)
const telRecibe = telefonoDe(() => asistente.b.recibe)

/** 6.3: Enviar arranca con la recogida; Recibir, con la entrega. Ubicación actual o "Casa". */
async function ubicacionInicial(casa: Direccion | undefined): Promise<Punto | null> {
  const desdeCasa = casa ? { direccion: casa.direccion, lat: casa.lat, lng: casa.lng } : null
  try {
    const permiso = await navigator.permissions?.query({ name: 'geolocation' })
    if (permiso?.state !== 'granted') return desdeCasa
    const pos = await new Promise<GeolocationPosition>((ok, mal) =>
      navigator.geolocation.getCurrentPosition(ok, mal, { timeout: 6000 }),
    )
    const c = { lat: pos.coords.latitude, lng: pos.coords.longitude }
    if (!(await api.validarPunto(c))) return desdeCasa
    return { ...c, direccion: await api.direccionDe(c) }
  } catch {
    return desdeCasa
  }
}

onMounted(async () => {
  favoritas.value = await api.direcciones()
  if (route.query.repetir) {
    // RN-55: el store ya trae los datos del pedido anterior.
  } else if (asistente.restaurarBorrador(tipo)) {
    ui.avisar('Retomamos tu pedido donde lo dejaste')
  } else {
    const casa = favoritas.value.find((f) => f.alias === 'Casa')
    asistente.iniciar(tipo, await ubicacionInicial(casa))
  }
  listo.value = true
})

// Al cambiar de paso, arriba de la pantalla.
watch(paso, () => window.scrollTo({ top: 0 }))

function irA(p: Paso): void {
  asistente.b.paso = p
}

function atras(): void {
  if (paso.value === 1) {
    router.push({ name: 'inicio', params: { slug: marca.slug } })
    return
  }
  irA((paso.value - 1) as Paso)
}

const puedeSeguir = computed(() => asistente.pasoValido[paso.value])

async function siguiente(): Promise<void> {
  if (!puedeSeguir.value) return
  if (paso.value < 4) {
    irA((paso.value + 1) as Paso)
    return
  }
  await confirmar()
}

async function confirmar(): Promise<void> {
  const solicitud = asistente.solicitud
  const cotizacion = asistente.cotizacion
  if (!solicitud || !cotizacion || enviando.value) return
  enviando.value = true
  try {
    const r = await api.crearPedido(solicitud, cotizacion, asistente.llave)
    if (r.ok) {
      asistente.descartarBorrador()
      await router.replace({ name: 'pedido', params: { slug: marca.slug, id: r.pedido.id } })
      ofrecerSiToca('pedido')
      return
    }
    if (r.motivo === 'PRECIO_CAMBIO') {
      // RN-24: el precio nuevo se muestra y se confirma otra vez.
      asistente.cotizacion = r.cotizacion
      ui.avisar(`El precio cambió a ${pesos(r.cotizacion.total)}. Revísalo y confirma.`, 'error')
    } else if (r.motivo === 'LIMITE') {
      ui.avisar('Tienes varios pedidos en curso. Espera a que termine alguno.', 'error')
    } else {
      router.replace({ name: 'no-disponible', params: { slug: marca.slug } })
    }
  } catch {
    ui.avisar('No pudimos enviar tu pedido. Revisa tu conexión e intenta de nuevo.', 'error')
  } finally {
    enviando.value = false
  }
}

const textoBoton = computed(() => {
  if (paso.value < 4) return 'Siguiente'
  return 'Confirmar y solicitar servicio'
})
</script>

<template>
  <main class="flex min-h-dvh flex-col">
    <header class="pt-seguro sticky top-0 z-20 bg-fondo/95 pb-3 backdrop-blur">
      <div class="flex items-center gap-2 px-3 pb-3">
        <button
          type="button"
          class="flex size-11 items-center justify-center rounded-full active:bg-morado-50"
          :aria-label="paso === 1 ? 'Salir' : 'Paso anterior'"
          @click="atras"
        >
          <Icon :icon="paso === 1 ? 'tabler:x' : 'tabler:arrow-left'" class="size-6" />
        </button>
        <h1 class="font-titulo text-xl font-bold">{{ titulo }}</h1>
      </div>
      <BarraPasos :paso="paso" />
    </header>

    <div v-if="listo" class="flex-1 px-5 pt-3 pb-36">
      <!-- Paso 1: Recogida -->
      <section v-if="paso === 1" class="space-y-5">
        <h2 class="font-titulo text-2xl font-extrabold">¿Dónde recogemos?</h2>
        <BuscadorDireccion
          v-model="asistente.b.recogida"
          etiqueta="Dirección de recogida"
          :favoritas="favoritas"
        />
        <CampoTexto
          v-model="asistente.b.referenciasRecogida"
          etiqueta="Referencias (opcional)"
          placeholder="Ej. portón negro, timbre 2"
          :maximo="255"
        />
        <label class="flex min-h-14 items-center gap-3 rounded-2xl bg-white px-4 ring-1 ring-linea">
          <span class="flex-1 text-sm font-semibold">Entrega el paquete otra persona</span>
          <input
            v-model="asistente.b.entregaOtraPersona"
            type="checkbox"
            role="switch"
            class="peer sr-only"
          />
          <span
            class="relative h-7 w-12 rounded-full bg-linea transition-colors peer-checked:bg-morado-600 after:absolute after:top-1 after:left-1 after:size-5 after:rounded-full after:bg-white after:transition-transform peer-checked:after:translate-x-5"
            aria-hidden="true"
          />
        </label>
        <div v-if="b.entregaOtraPersona" class="space-y-4">
          <CampoTexto
            v-model="asistente.b.contactoRecogida.nombre"
            etiqueta="¿Quién lo entrega?"
            placeholder="Nombre"
          />
          <CampoTexto
            v-model="telContacto"
            etiqueta="Su teléfono"
            tipo="tel"
            modo-teclado="tel"
            prefijo="+52"
            placeholder="461 123 4567"
          />
        </div>
      </section>

      <!-- Paso 2: Entrega -->
      <section v-else-if="paso === 2" class="space-y-5">
        <h2 class="font-titulo text-2xl font-extrabold">¿A dónde lo llevamos?</h2>
        <BuscadorDireccion
          v-model="asistente.b.entrega"
          etiqueta="Dirección de entrega"
          :favoritas="favoritas"
        />
        <CampoTexto
          v-model="asistente.b.referenciasEntrega"
          etiqueta="Referencias (opcional)"
          placeholder="Ej. casa azul, entre calles"
          :maximo="255"
        />
        <CampoTexto
          v-model="asistente.b.recibe.nombre"
          etiqueta="¿Quién recibe?"
          placeholder="Nombre"
          autocompletar="off"
        />
        <CampoTexto
          v-model="telRecibe"
          etiqueta="Su teléfono"
          tipo="tel"
          modo-teclado="tel"
          prefijo="+52"
          placeholder="461 123 4567"
        />
      </section>

      <PasoDetalles v-else-if="paso === 3" />
      <PasoConfirmacion v-else @volver-a="irA" />
    </div>

    <footer
      class="pb-seguro fixed inset-x-0 bottom-0 z-20 mx-auto max-w-md bg-linear-to-t from-fondo via-fondo to-fondo/0 px-5 pt-6"
    >
      <BotonPrincipal
        :variante="paso === 4 ? 'amarillo' : 'morado'"
        :deshabilitado="!puedeSeguir"
        :cargando="enviando"
        @click="siguiente"
      >
        {{ textoBoton }}
      </BotonPrincipal>
    </footer>
  </main>
</template>
