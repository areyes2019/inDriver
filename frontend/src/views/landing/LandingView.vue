<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { vAparecer } from './aparecer'
import MaquetaWhatsapp from './components/MaquetaWhatsapp.vue'
import FormularioContacto from './components/FormularioContacto.vue'

/**
 * Página pública de motopanda.mx: qué hace el sistema para una flotilla de motos, cuánto cuesta y
 * cómo contactarnos. Todo lo que promete existe hoy en el sistema.
 *
 * Las imágenes viven en `public/landing/` en WebP, en dos anchos: el navegador elige con `srcset`
 * la que le toca a su pantalla. WhatsApp no tiene imagen y usa una maqueta animada.
 */
const PRECIO_POR_VIAJE = 3

interface ImagenLanding {
  src: string
  srcset: string
  sizes: string
  ancho: number
  alto: number
  alt: string
  /** Fotos de teléfono con fondo blanco: se funden con el fondo de la sección. */
  fundir?: boolean
}

const imagenes: Record<string, ImagenLanding> = {
  portada: {
    src: '/landing/portada-960.webp',
    srcset: '/landing/portada-960.webp 960w, /landing/portada-1600.webp 1600w',
    sizes: '(min-width: 1024px) 45vw, 100vw',
    ancho: 1600,
    alto: 901,
    alt: 'Panel de Motopanda con los repartidores de una flotilla en el mapa de Celaya',
  },
  panel: {
    src: '/landing/panel-640.webp',
    srcset: '/landing/panel-640.webp 640w, /landing/panel-1100.webp 1100w',
    sizes: '(min-width: 1024px) 40vw, 100vw',
    ancho: 1100,
    alto: 1100,
    alt: 'Panel de Motopanda: mapa con cinco repartidores en línea y el estado de cada entrega',
  },
  'app-clientes': {
    src: '/landing/app-clientes-460.webp',
    srcset: '/landing/app-clientes-460.webp 460w, /landing/app-clientes-831.webp 831w',
    sizes: '(min-width: 1024px) 22rem, 70vw',
    ancho: 831,
    alto: 1893,
    alt: 'App de clientes con el logo y el banner de la flotilla y los botones Enviar y Recibir',
    fundir: true,
  },
  repartidores: {
    src: '/landing/app-repartidor-460.webp',
    srcset: '/landing/app-repartidor-460.webp 460w, /landing/app-repartidor-831.webp 831w',
    sizes: '(min-width: 1024px) 22rem, 70vw',
    ancho: 831,
    alto: 1893,
    alt: 'App del repartidor con su servicio activo, la recogida, la entrega y el pago del viaje',
    fundir: true,
  },
}

const enlaces = [
  { href: '#beneficios', texto: 'Beneficios' },
  { href: '#como-funciona', texto: 'Cómo funciona' },
  { href: '#precio', texto: 'Precio' },
  { href: '#preguntas', texto: 'Preguntas' },
]

const menuAbierto = ref(false)
const conScroll = ref(false)

function alHacerScroll() {
  conScroll.value = window.scrollY > 12
}

onMounted(() => {
  document.title = 'Motopanda · Software para flotillas de mensajería en moto'
  alHacerScroll()
  window.addEventListener('scroll', alHacerScroll, { passive: true })
})
onBeforeUnmount(() => window.removeEventListener('scroll', alHacerScroll))

const problemas = [
  { icono: 'tabler:messages', texto: 'Pedidos regados en WhatsApp, anotados a mano y repetidos.' },
  { icono: 'tabler:map-pin', texto: '"¿Dónde va mi paquete?" y tú llamándole a cada repartidor.' },
  { icono: 'tabler:cash', texto: 'Cobros y comisiones en libreta que nunca cuadran.' },
  { icono: 'tabler:clock', texto: 'Clientes que esperan porque nadie vio el pedido a tiempo.' },
]

const destacados = [
  {
    id: 'panel',
    etiqueta: 'Panel en tiempo real',
    titulo: 'Toda tu operación en un mapa',
    texto:
      'Ve a cada repartidor moverse en vivo, el estado de cada envío y quién va libre. Crea envíos en segundos: la tarifa se calcula sola por distancia real de calles.',
    puntos: [
      'Ubicación de tus repartidores en vivo',
      'Viajes en turno con su estado al instante',
      'Cotización automática por banderazo y kilómetro',
      'Cancela o cambia el destino de un envío desde el Panel',
    ],
  },
  {
    id: 'whatsapp',
    etiqueta: 'WhatsApp con asistente',
    titulo: 'Tus clientes piden por WhatsApp. El asistente hace el resto',
    texto:
      'Un asistente con inteligencia artificial entiende el pedido, cotiza, confirma con el cliente y lo publica a tus repartidores. Si el cliente pide hablar con una persona, te pasa la conversación.',
    puntos: [
      'Atiende a cualquier hora, sin que nadie teclee',
      'Cotiza con tus tarifas y tu zona de cobertura',
      'Avisos automáticos al cliente: asignado, en camino, entregado',
      'Bandeja en el Panel para contestar tú cuando haga falta',
    ],
  },
  {
    id: 'app-clientes',
    etiqueta: 'App con tu marca',
    titulo: 'Una app para tus clientes, con tu logo',
    texto:
      'Tus clientes la instalan en su celular desde una liga, sin tiendas de apps. Piden, ven el costo antes de confirmar y siguen a su repartidor en el mapa.',
    puntos: [
      'Tu logo y tu banner, no los nuestros',
      'Enviar y Recibir en dos toques',
      'Seguimiento en vivo y tiempo estimado de llegada',
      'Direcciones favoritas e historial de pedidos',
    ],
  },
  {
    id: 'repartidores',
    etiqueta: 'App para repartidores',
    titulo: 'Tus repartidores, conectados y ordenados',
    texto:
      'Cada repartidor recibe ofertas de viaje con el pago a la vista y las acepta con un toque. Navega con Google Maps o Waze y marca cada paso del envío.',
    puntos: [
      'Ofertas de viaje con tiempo para aceptar',
      'Viajes prepagados o comisión por envío',
      'Recogida, camino y entrega paso a paso',
      'Su ubicación viaja al Panel sola',
    ],
  },
]

const beneficios = [
  {
    icono: 'tabler:clock-bolt',
    titulo: 'Modo automático',
    texto:
      'Si nadie toma un envío, el sistema lo vuelve a ofrecer cada 5 minutos y lo cancela solo si pasa media hora sin repartidor.',
  },
  {
    icono: 'tabler:map-pins',
    titulo: 'Zonas de cobertura',
    texto: 'Dibuja en el mapa hasta dónde llegas. Nadie te pide un envío fuera de tu zona.',
  },
  {
    icono: 'tabler:coin',
    titulo: 'Tarifas a tu medida',
    texto: 'Banderazo, kilómetros incluidos y costo por kilómetro extra. Tú decides cuánto cobrar.',
  },
  {
    icono: 'tabler:receipt',
    titulo: 'Cobro contra entrega',
    texto: 'Envíos donde el repartidor cobra el producto, con el importe claro desde el inicio.',
  },
  {
    icono: 'tabler:users',
    titulo: 'Despachadores',
    texto: 'Reparte el trabajo: cada despachador ve y maneja a sus propios repartidores.',
  },
  {
    icono: 'tabler:chart-bar',
    titulo: 'Reportes y liquidaciones',
    texto: 'Pagos de repartidores, comisiones pendientes y liquidaciones, sin hojas de cálculo.',
  },
  {
    icono: 'tabler:user-check',
    titulo: 'Clientes ordenados',
    texto: 'Cada cliente con sus direcciones e historial. Detecta duplicados y los une.',
  },
  {
    icono: 'tabler:shield-check',
    titulo: 'Tus datos aparte',
    texto: 'Cada flotilla tiene su propia base de datos. Nadie más ve tu información.',
  },
]

const pasos = [
  {
    titulo: 'Escríbenos',
    texto: 'Llenas el formulario y te contactamos para dar de alta tu flotilla.',
  },
  {
    titulo: 'Configura en minutos',
    texto: 'Tus tarifas, tu zona en el mapa, tus repartidores y tu logo.',
  },
  {
    titulo: 'Empieza a entregar',
    texto: 'Recibe pedidos del Panel, WhatsApp y tu app. Pagas solo por viaje entregado.',
  },
]

// Calculadora del precio: cuántos viajes entregados al mes.
const viajesAlMes = ref(600)
const costoMensual = computed(() => viajesAlMes.value * PRECIO_POR_VIAJE)
const formatoPesos = new Intl.NumberFormat('es-MX', {
  style: 'currency',
  currency: 'MXN',
  maximumFractionDigits: 0,
})

const incluido = [
  'Panel con mapa en tiempo real',
  'Pedidos por WhatsApp con asistente',
  'App de clientes con tu marca',
  'App para tus repartidores',
  'Modo automático y zonas de cobertura',
  'Repartidores y despachadores ilimitados',
  'Reportes y liquidaciones',
  'Soporte directo con nuestro equipo',
]

const preguntas = [
  {
    p: '¿Cómo se cobra exactamente?',
    r: '$3.00 MXN por cada viaje entregado. Compras paquetes de viajes por adelantado y se van descontando conforme entregas. Sin mensualidad, sin costo de instalación y sin cobro por repartidor.',
  },
  {
    p: '¿Hay contrato o plazo forzoso?',
    r: 'No. No firmas contrato ni te comprometes a un mínimo. Si un mes no entregas, no pagas nada.',
  },
  {
    p: '¿Un viaje cancelado cuenta?',
    r: 'No. Solo cuentan los envíos que se entregan.',
  },
  {
    p: '¿Mis repartidores necesitan un celular especial?',
    r: 'No. La app del repartidor funciona en cualquier celular Android con GPS e internet.',
  },
  {
    p: '¿Mis clientes tienen que descargar algo de la tienda?',
    r: 'No. La app de clientes se instala desde una liga que tú compartes, en Android y en iPhone. Y quien prefiera seguir pidiendo por WhatsApp, puede hacerlo.',
  },
  {
    p: '¿Puedo usar mi propio número de WhatsApp?',
    r: 'Sí. Conectamos el número de WhatsApp Business de tu flotilla para que el asistente conteste a tu nombre.',
  },
  {
    p: '¿Qué pasa con mis datos?',
    r: 'Son tuyos. Cada flotilla tiene su base de datos separada y no compartimos tu información con nadie.',
  },
]
</script>

<template>
  <div class="min-h-screen bg-white font-sans text-tinta antialiased">
    <!-- Encabezado -->
    <header
      class="fixed inset-x-0 top-0 z-50 transition-all duration-300"
      :class="conScroll ? 'bg-white/85 shadow-sm backdrop-blur-lg' : 'bg-transparent'"
    >
      <nav class="mx-auto flex h-16 max-w-7xl items-center gap-6 px-4 sm:px-6 lg:px-8">
        <a href="#inicio" class="flex items-center gap-2">
          <img src="/pide/iconos/icono-192.png" alt="" class="size-9 rounded-xl" />
          <span
            class="font-heading text-xl font-extrabold tracking-tight"
            :class="conScroll ? 'text-tinta' : 'text-white'"
            >Motopanda</span
          >
        </a>
        <div class="ml-auto hidden items-center gap-7 md:flex">
          <a
            v-for="enlace in enlaces"
            :key="enlace.href"
            :href="enlace.href"
            class="text-sm font-medium transition hover:opacity-100"
            :class="conScroll ? 'text-tinta/70 hover:text-panda-700' : 'text-white/80'"
            >{{ enlace.texto }}</a
          >
          <a
            href="#contacto"
            class="rounded-full bg-sol-500 px-5 py-2.5 text-sm font-bold text-panda-950 shadow-md transition hover:-translate-y-0.5 hover:bg-sol-400"
            >Contáctanos</a
          >
        </div>
        <button
          type="button"
          class="ml-auto rounded-lg p-2 md:hidden"
          :class="conScroll ? 'text-tinta' : 'text-white'"
          :aria-expanded="menuAbierto"
          aria-label="Abrir menú"
          @click="menuAbierto = !menuAbierto"
        >
          <Icon :icon="menuAbierto ? 'tabler:x' : 'tabler:menu-2'" class="size-6" />
        </button>
      </nav>
      <div
        v-if="menuAbierto"
        class="border-t border-gray-100 bg-white px-4 pb-5 shadow-lg md:hidden"
      >
        <a
          v-for="enlace in enlaces"
          :key="enlace.href"
          :href="enlace.href"
          class="block py-3 font-medium text-tinta/80"
          @click="menuAbierto = false"
          >{{ enlace.texto }}</a
        >
        <a
          href="#contacto"
          class="mt-2 block rounded-xl bg-panda-700 py-3 text-center font-bold text-white"
          @click="menuAbierto = false"
          >Contáctanos</a
        >
      </div>
    </header>

    <!-- Portada -->
    <section
      id="inicio"
      class="relative overflow-hidden bg-gradient-to-br from-panda-950 via-panda-900 to-panda-700 pt-28 pb-20 text-white sm:pt-36 lg:pb-28"
    >
      <div class="mancha mancha-1" aria-hidden="true" />
      <div class="mancha mancha-2" aria-hidden="true" />
      <div class="rejilla" aria-hidden="true" />

      <div
        class="relative mx-auto grid max-w-7xl items-center gap-14 px-4 sm:px-6 lg:grid-cols-[1.05fr_1fr] lg:px-8"
      >
        <div v-aparecer>
          <p
            class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-4 py-1.5 text-xs font-semibold text-sol-400 backdrop-blur"
          >
            <Icon icon="tabler:sparkles" class="size-4" /> Software para flotillas de mensajería en
            moto
          </p>
          <h1
            class="mt-6 font-heading text-4xl leading-[1.05] font-extrabold tracking-tight sm:text-5xl lg:text-6xl"
          >
            Tu flotilla de motos,
            <span class="bg-gradient-to-r from-sol-400 to-sol-500 bg-clip-text text-transparent"
              >bajo control</span
            >
            en una sola pantalla
          </h1>
          <p class="mt-6 max-w-xl text-lg leading-relaxed text-white/75">
            Recibe pedidos por WhatsApp, por tu propia app y por el Panel. Asígnalos, síguelos en el
            mapa y cobra sin libretas. Pagas solo
            <strong class="text-white">$3 por viaje entregado</strong>.
          </p>
          <div class="mt-9 flex flex-col gap-3 sm:flex-row">
            <a
              href="#contacto"
              class="inline-flex items-center justify-center gap-2 rounded-2xl bg-sol-500 px-7 py-4 font-bold text-panda-950 shadow-xl shadow-sol-500/25 transition hover:-translate-y-0.5 hover:bg-sol-400"
            >
              Quiero probarlo <Icon icon="tabler:arrow-right" class="size-5" />
            </a>
            <a
              href="#beneficios"
              class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 px-7 py-4 font-semibold text-white transition hover:bg-white/10"
            >
              Ver lo que hace
            </a>
          </div>
          <ul class="mt-9 flex flex-wrap gap-x-6 gap-y-2 text-sm text-white/70">
            <li
              v-for="dato in ['Sin contratos', 'Sin mensualidad', 'Sin compromisos']"
              :key="dato"
              class="flex items-center gap-1.5"
            >
              <Icon icon="tabler:circle-check" class="size-4 text-sol-400" /> {{ dato }}
            </li>
          </ul>
        </div>

        <div v-aparecer="150" class="relative">
          <div class="flotar">
            <img
              :src="imagenes.portada!.src"
              :srcset="imagenes.portada!.srcset"
              :sizes="imagenes.portada!.sizes"
              :width="imagenes.portada!.ancho"
              :height="imagenes.portada!.alto"
              :alt="imagenes.portada!.alt"
              fetchpriority="high"
              class="h-auto w-full rounded-3xl shadow-2xl shadow-panda-950/40 ring-1 ring-white/15"
            />
          </div>
        </div>
      </div>
    </section>

    <!-- Cinta de datos -->
    <section class="border-b border-gray-100 bg-white">
      <div
        class="mx-auto grid max-w-7xl grid-cols-2 gap-6 px-4 py-10 text-center sm:px-6 md:grid-cols-4 lg:px-8"
      >
        <div
          v-for="(dato, i) in [
            { valor: '$3', texto: 'por viaje entregado' },
            { valor: '0', texto: 'contratos y mensualidades' },
            { valor: '24/7', texto: 'asistente en WhatsApp' },
            { valor: 'En vivo', texto: 'repartidores en el mapa' },
          ]"
          :key="dato.texto"
          v-aparecer="i * 80"
        >
          <p class="font-heading text-3xl font-extrabold text-panda-700 sm:text-4xl">
            {{ dato.valor }}
          </p>
          <p class="mt-1 text-sm text-tinta/55">{{ dato.texto }}</p>
        </div>
      </div>
    </section>

    <!-- El problema -->
    <section class="bg-panda-50/60 py-20 sm:py-24">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div v-aparecer class="mx-auto max-w-2xl text-center">
          <p class="text-sm font-bold tracking-widest text-panda-600 uppercase">¿Te suena?</p>
          <h2 class="mt-3 font-heading text-3xl font-extrabold tracking-tight sm:text-4xl">
            Llevar una flotilla a mano cuesta caro
          </h2>
        </div>
        <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div
            v-for="(problema, i) in problemas"
            :key="problema.texto"
            v-aparecer="i * 90"
            class="rounded-2xl border border-panda-100 bg-white p-6"
          >
            <span class="flex size-11 items-center justify-center rounded-xl bg-red-50">
              <Icon :icon="problema.icono" class="size-6 text-red-500" />
            </span>
            <p class="mt-4 leading-relaxed text-tinta/75">{{ problema.texto }}</p>
          </div>
        </div>
        <p v-aparecer class="mt-10 text-center font-heading text-xl font-bold text-panda-700">
          Motopanda pone todo en un solo lugar. ↓
        </p>
      </div>
    </section>

    <!-- Destacados -->
    <section id="beneficios" class="py-20 sm:py-28">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div v-aparecer class="mx-auto max-w-2xl text-center">
          <p class="text-sm font-bold tracking-widest text-panda-600 uppercase">Beneficios</p>
          <h2 class="mt-3 font-heading text-3xl font-extrabold tracking-tight sm:text-5xl">
            Todo lo que tu flotilla necesita
          </h2>
          <p class="mt-4 text-lg text-tinta/60">
            Un sistema para recibir, repartir y cobrar. Para ti, tus repartidores y tus clientes.
          </p>
        </div>

        <div class="mt-20 space-y-24 sm:space-y-32">
          <article
            v-for="(destacado, i) in destacados"
            :id="destacado.id"
            :key="destacado.id"
            class="grid items-center gap-12 lg:grid-cols-2 lg:gap-20"
          >
            <div v-aparecer :class="i % 2 === 1 ? 'lg:order-2' : ''">
              <p
                class="inline-flex rounded-full bg-panda-100 px-3 py-1 text-xs font-bold text-panda-700"
              >
                {{ destacado.etiqueta }}
              </p>
              <h3 class="mt-4 font-heading text-2xl font-extrabold tracking-tight sm:text-4xl">
                {{ destacado.titulo }}
              </h3>
              <p class="mt-4 text-lg leading-relaxed text-tinta/65">{{ destacado.texto }}</p>
              <ul class="mt-6 space-y-3">
                <li
                  v-for="punto in destacado.puntos"
                  :key="punto"
                  class="flex items-start gap-3 text-tinta/80"
                >
                  <span
                    class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-sol-100"
                  >
                    <Icon icon="tabler:check" class="size-3.5 text-panda-700" />
                  </span>
                  {{ punto }}
                </li>
              </ul>
            </div>
            <div
              v-aparecer="120"
              class="relative flex justify-center"
              :class="i % 2 === 1 ? 'lg:order-1' : ''"
            >
              <div
                class="absolute inset-6 -z-10 rounded-[3rem] bg-gradient-to-br from-panda-100 to-sol-100 blur-2xl"
                aria-hidden="true"
              />
              <MaquetaWhatsapp v-if="destacado.id === 'whatsapp'" />
              <img
                v-else-if="imagenes[destacado.id]"
                :src="imagenes[destacado.id]!.src"
                :srcset="imagenes[destacado.id]!.srcset"
                :sizes="imagenes[destacado.id]!.sizes"
                :width="imagenes[destacado.id]!.ancho"
                :height="imagenes[destacado.id]!.alto"
                :alt="imagenes[destacado.id]!.alt"
                loading="lazy"
                decoding="async"
                class="h-auto"
                :class="
                  imagenes[destacado.id]!.fundir
                    ? 'w-auto max-h-[34rem] mix-blend-multiply'
                    : 'w-full rounded-3xl shadow-2xl shadow-panda-900/20'
                "
              />
            </div>
          </article>
        </div>
      </div>
    </section>

    <!-- Más beneficios -->
    <section class="bg-tinta py-20 text-white sm:py-28">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div v-aparecer class="mx-auto max-w-2xl text-center">
          <h2 class="font-heading text-3xl font-extrabold tracking-tight sm:text-4xl">
            Y mucho más, incluido en el mismo precio
          </h2>
        </div>
        <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <div
            v-for="(beneficio, i) in beneficios"
            :key="beneficio.titulo"
            v-aparecer="(i % 4) * 80"
            class="tarjeta-oscura rounded-2xl border border-white/10 bg-white/[0.04] p-6 transition"
          >
            <span
              class="flex size-12 items-center justify-center rounded-xl bg-gradient-to-br from-panda-500 to-panda-700"
            >
              <Icon :icon="beneficio.icono" class="size-6 text-white" />
            </span>
            <p class="mt-5 font-heading text-lg font-bold">{{ beneficio.titulo }}</p>
            <p class="mt-2 text-sm leading-relaxed text-white/60">{{ beneficio.texto }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- Cómo funciona -->
    <section id="como-funciona" class="py-20 sm:py-28">
      <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
        <div v-aparecer class="text-center">
          <p class="text-sm font-bold tracking-widest text-panda-600 uppercase">Cómo funciona</p>
          <h2 class="mt-3 font-heading text-3xl font-extrabold tracking-tight sm:text-4xl">
            Arranca hoy, en tres pasos
          </h2>
        </div>
        <ol class="relative mt-14 grid gap-8 md:grid-cols-3">
          <div
            class="absolute top-7 right-[16%] left-[16%] hidden h-0.5 bg-gradient-to-r from-panda-200 via-panda-500 to-panda-200 md:block"
            aria-hidden="true"
          />
          <li
            v-for="(paso, i) in pasos"
            :key="paso.titulo"
            v-aparecer="i * 130"
            class="relative text-center"
          >
            <span
              class="relative mx-auto flex size-14 items-center justify-center rounded-2xl bg-panda-700 font-heading text-2xl font-extrabold text-white shadow-lg shadow-panda-700/30"
              >{{ i + 1 }}</span
            >
            <p class="mt-5 font-heading text-xl font-bold">{{ paso.titulo }}</p>
            <p class="mt-2 text-tinta/60">{{ paso.texto }}</p>
          </li>
        </ol>
      </div>
    </section>

    <!-- Precio -->
    <section
      id="precio"
      class="relative overflow-hidden bg-gradient-to-b from-panda-50 to-white py-20 sm:py-28"
    >
      <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
        <div v-aparecer class="mx-auto max-w-2xl text-center">
          <p class="text-sm font-bold tracking-widest text-panda-600 uppercase">Precio</p>
          <h2 class="mt-3 font-heading text-3xl font-extrabold tracking-tight sm:text-5xl">
            Un solo plan. Pagas lo que entregas
          </h2>
          <p class="mt-4 text-lg text-tinta/60">
            Sin contratos, sin compromisos, sin mensualidad y sin costo de instalación.
          </p>
        </div>

        <div class="mt-14 grid items-stretch gap-6 lg:grid-cols-[1.1fr_1fr]">
          <div
            v-aparecer
            class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-panda-800 to-panda-950 p-8 text-white shadow-2xl shadow-panda-900/30 sm:p-10"
          >
            <div class="mancha mancha-precio" aria-hidden="true" />
            <p
              class="relative inline-flex rounded-full bg-sol-500 px-3 py-1 text-xs font-extrabold text-panda-950"
            >
              Plan único
            </p>
            <div class="relative mt-6 flex items-end gap-2">
              <span class="font-heading text-7xl font-extrabold tracking-tight">$3</span>
              <span class="mb-3 text-lg text-white/70">.00 MXN<br />por viaje</span>
            </div>
            <p class="relative mt-3 text-white/70">
              Solo cuentan los viajes entregados. Los cancelados no se cobran.
            </p>
            <ul class="relative mt-8 grid gap-3 sm:grid-cols-2">
              <li
                v-for="cosa in incluido"
                :key="cosa"
                class="flex items-start gap-2.5 text-sm text-white/85"
              >
                <Icon icon="tabler:circle-check" class="mt-0.5 size-4.5 shrink-0 text-sol-400" />
                {{ cosa }}
              </li>
            </ul>
            <a
              href="#contacto"
              class="relative mt-9 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-sol-500 py-4 font-bold text-panda-950 transition hover:bg-sol-400 sm:w-auto sm:px-9"
            >
              Empezar ahora <Icon icon="tabler:arrow-right" class="size-5" />
            </a>
          </div>

          <div
            v-aparecer="120"
            class="flex flex-col rounded-3xl border border-panda-100 bg-white p-8 shadow-xl shadow-panda-900/5 sm:p-10"
          >
            <p class="font-heading text-xl font-bold">Calcula tu costo</p>
            <p class="mt-1 text-sm text-tinta/55">¿Cuántos viajes entregas al mes?</p>
            <p class="mt-8 font-heading text-5xl font-extrabold text-panda-700">
              {{ viajesAlMes.toLocaleString('es-MX') }}
              <span class="text-lg font-semibold text-tinta/40">viajes</span>
            </p>
            <input
              v-model.number="viajesAlMes"
              type="range"
              min="50"
              max="5000"
              step="50"
              class="deslizador mt-6 w-full"
              aria-label="Viajes entregados al mes"
            />
            <div class="mt-1 flex justify-between text-xs text-tinta/40">
              <span>50</span><span>5,000</span>
            </div>
            <div class="mt-auto rounded-2xl bg-panda-50 p-5 pt-5">
              <p class="text-sm text-tinta/60">Pagarías al mes</p>
              <p class="mt-1 font-heading text-4xl font-extrabold text-tinta">
                {{ formatoPesos.format(costoMensual) }}
              </p>
              <p class="mt-2 text-xs text-tinta/50">
                Equivale a {{ formatoPesos.format(costoMensual / 30) }} al día. Y si un mes no
                entregas, no pagas nada.
              </p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Preguntas -->
    <section id="preguntas" class="py-20 sm:py-28">
      <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
        <div v-aparecer class="text-center">
          <p class="text-sm font-bold tracking-widest text-panda-600 uppercase">Preguntas</p>
          <h2 class="mt-3 font-heading text-3xl font-extrabold tracking-tight sm:text-4xl">
            Lo que más nos preguntan
          </h2>
        </div>
        <div class="mt-12 space-y-3">
          <details
            v-for="(pregunta, i) in preguntas"
            :key="pregunta.p"
            v-aparecer="i * 50"
            class="pregunta group rounded-2xl border border-gray-200 bg-white px-6 py-1 transition open:border-panda-200 open:shadow-lg open:shadow-panda-900/5"
          >
            <summary
              class="flex cursor-pointer list-none items-center justify-between gap-4 py-4 font-heading text-lg font-bold"
            >
              {{ pregunta.p }}
              <Icon
                icon="tabler:chevron-down"
                class="size-5 shrink-0 text-panda-600 transition-transform group-open:rotate-180"
              />
            </summary>
            <p class="pb-5 leading-relaxed text-tinta/65">{{ pregunta.r }}</p>
          </details>
        </div>
      </div>
    </section>

    <!-- Contacto -->
    <section id="contacto" class="bg-panda-50/70 py-20 sm:py-28">
      <div class="mx-auto grid max-w-6xl gap-12 px-4 sm:px-6 lg:grid-cols-[1fr_1.4fr] lg:px-8">
        <div v-aparecer>
          <p class="text-sm font-bold tracking-widest text-panda-600 uppercase">Contacto</p>
          <h2 class="mt-3 font-heading text-3xl font-extrabold tracking-tight sm:text-4xl">
            Ponle orden a tu flotilla esta semana
          </h2>
          <p class="mt-4 text-lg text-tinta/60">
            Cuéntanos de tu operación y te contactamos para darte de alta. Sin costo de instalación
            y sin compromiso.
          </p>
          <div class="mt-8 space-y-4">
            <a
              href="mailto:contacto@motopanda.mx"
              class="flex items-center gap-3 text-tinta/75 transition hover:text-panda-700"
            >
              <span class="flex size-11 items-center justify-center rounded-xl bg-white shadow-sm">
                <Icon icon="tabler:mail" class="size-5 text-panda-700" />
              </span>
              contacto@motopanda.mx
            </a>
            <p class="flex items-center gap-3 text-tinta/75">
              <span class="flex size-11 items-center justify-center rounded-xl bg-white shadow-sm">
                <Icon icon="tabler:clock" class="size-5 text-panda-700" />
              </span>
              Te contestamos personalmente
            </p>
          </div>
          <img
            src="/landing/mandados-960.webp"
            srcset="/landing/mandados-960.webp 960w, /landing/mandados-1600.webp 1600w"
            sizes="(min-width: 1024px) 26rem, 100vw"
            width="1600"
            height="900"
            alt="Repartidor de Moto Panda en la ciudad: tus mandados más rápidos y seguros"
            loading="lazy"
            decoding="async"
            class="mt-10 h-auto w-full rounded-3xl shadow-xl shadow-panda-900/15"
          />
        </div>
        <div
          v-aparecer="120"
          class="relative rounded-3xl bg-white p-6 shadow-xl shadow-panda-900/5 sm:p-10"
        >
          <FormularioContacto />
        </div>
      </div>
    </section>

    <!-- Pie -->
    <footer class="bg-panda-950 py-12 text-white/60">
      <div
        class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-6 px-4 sm:px-6 md:flex-row lg:px-8"
      >
        <div class="flex items-center gap-2">
          <img src="/pide/iconos/icono-192.png" alt="" class="size-8 rounded-lg" />
          <span class="font-heading text-lg font-extrabold text-white">Motopanda</span>
        </div>
        <div class="flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm">
          <a href="#beneficios" class="hover:text-white">Beneficios</a>
          <a href="#precio" class="hover:text-white">Precio</a>
          <a href="#contacto" class="hover:text-white">Contacto</a>
          <a href="/privacidad/" class="hover:text-white">Aviso de privacidad</a>
        </div>
        <p class="text-sm">© {{ new Date().getFullYear() }} Motopanda</p>
      </div>
    </footer>
  </div>
</template>

<style scoped>
/* Entrada al hacer scroll (ver `aparecer.ts`). */
:deep(.por-aparecer) {
  opacity: 0;
  transform: translateY(24px);
  transition:
    opacity 0.7s ease,
    transform 0.7s cubic-bezier(0.2, 0.7, 0.2, 1);
}

:deep(.por-aparecer.aparecido) {
  opacity: 1;
  transform: none;
}

/* Manchas de color de la portada y del precio. */
.mancha {
  position: absolute;
  border-radius: 9999px;
  filter: blur(80px);
  pointer-events: none;
}

.mancha-1 {
  top: -10rem;
  right: -8rem;
  width: 36rem;
  height: 36rem;
  background: rgb(124 58 237 / 0.55);
  animation: deriva 14s ease-in-out infinite alternate;
}

.mancha-2 {
  bottom: -12rem;
  left: -10rem;
  width: 30rem;
  height: 30rem;
  background: rgb(250 204 21 / 0.18);
  animation: deriva 18s ease-in-out infinite alternate-reverse;
}

.mancha-precio {
  top: -8rem;
  right: -8rem;
  width: 22rem;
  height: 22rem;
  background: rgb(250 204 21 / 0.25);
}

.rejilla {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgb(255 255 255 / 0.05) 1px, transparent 1px),
    linear-gradient(90deg, rgb(255 255 255 / 0.05) 1px, transparent 1px);
  background-size: 48px 48px;
  mask-image: radial-gradient(ellipse at 60% 30%, black 20%, transparent 70%);
  pointer-events: none;
}

@keyframes deriva {
  to {
    transform: translate(-4rem, 3rem) scale(1.1);
  }
}

.flotar {
  animation: flotar 6s ease-in-out infinite;
}

@keyframes flotar {
  0%,
  100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-10px);
  }
}

.tarjeta-oscura:hover {
  border-color: rgb(167 139 250 / 0.5);
  background: rgb(255 255 255 / 0.07);
  transform: translateY(-4px);
}

.pregunta summary::-webkit-details-marker {
  display: none;
}

.deslizador {
  appearance: none;
  height: 0.5rem;
  border-radius: 9999px;
  background: #ede9fe;
  outline: none;
}

.deslizador::-webkit-slider-thumb {
  appearance: none;
  width: 1.6rem;
  height: 1.6rem;
  border-radius: 9999px;
  background: #5b21b6;
  border: 4px solid #fde047;
  cursor: pointer;
  box-shadow: 0 4px 12px rgb(91 33 182 / 0.4);
}

.deslizador::-moz-range-thumb {
  width: 1.2rem;
  height: 1.2rem;
  border-radius: 9999px;
  background: #5b21b6;
  border: 4px solid #fde047;
  cursor: pointer;
}

.deslizador:focus-visible::-webkit-slider-thumb {
  outline: 3px solid #a78bfa;
  outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
  .mancha-1,
  .mancha-2,
  .flotar {
    animation: none;
  }

  .tarjeta-oscura:hover {
    transform: none;
  }
}
</style>
