<script setup lang="ts">
/**
 * Bandeja de conversaciones de WhatsApp (spec tenant/031, etapa 7b, RN-54 a RN-60).
 *
 * La lista y la conversación abierta se vuelven a pedir cada vez que el canal del Panel avisa que
 * algo cambió (D22): el socket no trae el texto, solo dice qué conversación mirar.
 *
 * La usan la pantalla de Conversaciones y el panel de chat del Panel (spec tenant/032, paso 3).
 * `compacta` fuerza una sola columna —lista, y al tocar una conversación el chat con "← Volver"—:
 * sin ella las columnas dependen del ancho de la ventana (`md:`), y en un panel angosto dentro de
 * una pantalla ancha saldrían dos columnas apretadas. Ocupa todo el alto de su contenedor.
 */
import { computed, nextTick, onMounted, onUnmounted, ref, useId, watch } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/lib/http'
import realtimeService from '@/services/realtime'
import UiAlert from '@/components/ui/UiAlert.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiButton from '@/components/ui/UiButton.vue'

interface UltimoMensaje {
  texto: string | null
  tipo: string
  entrante: boolean
  enviado_en: string
}

interface Conversacion {
  id_conversacion: number
  nombre: string
  telefono: string | null
  id_cliente: number | null
  pide_persona: boolean
  pide_persona_en: string | null
  bot_pausado_hasta: string | null
  ultimo_mensaje: UltimoMensaje | null
}

interface Mensaje {
  id_mensaje: number
  autor: 'cliente' | 'bot' | 'persona' | 'aviso'
  nombre_usuario: string | null
  tipo: string
  texto: string | null
  borrado: boolean
  mapa_url: string | null
  boton: string | null
  folio: string | null
  estado_envio: string | null
  enviado_en: string
}

interface Detalle extends Conversacion {
  ventana_abierta: boolean
  ventana_cierra_en: string | null
  pedidos: Array<{ id_pedido: number; numero_pedido: string; estado: string }>
  mensajes: Mensaje[]
}

withDefaults(defineProps<{ compacta?: boolean }>(), { compacta: false })

const route = useRoute()
const slug = computed(() => route.params.slug as string)
// El campo de respuesta necesita un id propio para su <label>.
const idRespuesta = useId()

const conversaciones = ref<Conversacion[]>([])
const cargandoLista = ref(false)
const errorLista = ref('')

const seleccionada = ref<number | null>(null)
const detalle = ref<Detalle | null>(null)
const cargandoDetalle = ref(false)
const errorDetalle = ref('')

const respuesta = ref('')
const enviando = ref(false)
const errorEnvio = ref('')
const devolviendo = ref(false)

const historialRef = ref<HTMLDivElement>()

const hora = new Intl.DateTimeFormat('es-MX', {
  timeZone: 'America/Mexico_City',
  hour: 'numeric',
  minute: '2-digit',
})
const fechaCorta = new Intl.DateTimeFormat('es-MX', {
  timeZone: 'America/Mexico_City',
  day: 'numeric',
  month: 'short',
})
const diaMexico = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Mexico_City' })

function cuando(iso: string): string {
  const fecha = new Date(iso)
  return diaMexico.format(fecha) === diaMexico.format(new Date())
    ? hora.format(fecha)
    : `${fechaCorta.format(fecha)} ${hora.format(fecha)}`
}

function vistaPrevia(ultimo: UltimoMensaje | null): string {
  if (!ultimo) return ''
  const texto = ultimo.texto ?? (ultimo.tipo === 'location' ? '📍 Ubicación' : '(sin texto)')
  return ultimo.entrante ? texto : `Tú: ${texto}`
}

const autorEtiqueta: Record<Mensaje['autor'], string> = {
  cliente: 'Cliente',
  bot: 'Bot',
  persona: 'Persona',
  aviso: 'Aviso',
}

async function cargarLista() {
  cargandoLista.value = conversaciones.value.length === 0
  errorLista.value = ''

  try {
    const { data } = await http.get(`/t/${slug.value}/whatsapp/conversaciones`)
    conversaciones.value = data.data
  } catch {
    errorLista.value = 'No se pudo cargar la lista de conversaciones.'
  } finally {
    cargandoLista.value = false
  }
}

async function cargarDetalle(idConversacion: number, desplazar = true) {
  cargandoDetalle.value = detalle.value?.id_conversacion !== idConversacion
  errorDetalle.value = ''

  try {
    const { data } = await http.get(`/t/${slug.value}/whatsapp/conversaciones/${idConversacion}`)
    // Si mientras tanto se abrió otra, esta respuesta ya no sirve.
    if (seleccionada.value !== idConversacion) return
    detalle.value = data
    if (desplazar) bajarAlFinal()
  } catch {
    errorDetalle.value = 'No se pudo abrir la conversación.'
  } finally {
    cargandoDetalle.value = false
  }
}

function bajarAlFinal() {
  nextTick(() => {
    const el = historialRef.value
    if (el) el.scrollTop = el.scrollHeight
  })
}

function abrir(idConversacion: number) {
  if (seleccionada.value === idConversacion) return
  seleccionada.value = idConversacion
  respuesta.value = ''
  errorEnvio.value = ''
  cargarDetalle(idConversacion)
}

function cerrar() {
  seleccionada.value = null
  detalle.value = null
}

async function enviar() {
  const texto = respuesta.value.trim()
  if (!texto || !detalle.value || enviando.value) return

  enviando.value = true
  errorEnvio.value = ''

  try {
    await http.post(
      `/t/${slug.value}/whatsapp/conversaciones/${detalle.value.id_conversacion}/mensajes`,
      { texto },
    )
    respuesta.value = ''
    await cargarDetalle(detalle.value.id_conversacion)
  } catch (error: unknown) {
    const mensaje = (error as { response?: { data?: { message?: string } } }).response?.data
      ?.message
    errorEnvio.value = mensaje ?? 'No se pudo enviar el mensaje.'
  } finally {
    enviando.value = false
  }
}

function onTeclaRespuesta(event: KeyboardEvent) {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    enviar()
  }
}

async function devolverAlBot() {
  if (!detalle.value || devolviendo.value) return
  devolviendo.value = true

  try {
    await http.post(
      `/t/${slug.value}/whatsapp/conversaciones/${detalle.value.id_conversacion}/devolver-al-bot`,
    )
    await cargarDetalle(detalle.value.id_conversacion, false)
  } catch {
    errorEnvio.value = 'No se pudo devolver la conversación al bot.'
  } finally {
    devolviendo.value = false
  }
}

function onConversacionActualizada(datos: { id_conversacion?: number }) {
  cargarLista()
  if (datos.id_conversacion && datos.id_conversacion === seleccionada.value) {
    cargarDetalle(datos.id_conversacion)
  }
}

// Al volver de una caída del socket, lo que llegó mientras tanto no avisó a nadie.
let dejarDeObservar: (() => void) | null = null
let estadoAnterior = realtimeService.estadoConexion

onMounted(() => {
  cargarLista()
  realtimeService
    .subscribePanel(slug.value)
    ?.bind('whatsapp.conversacion', onConversacionActualizada)
  dejarDeObservar = realtimeService.alCambiarEstado((estado) => {
    if (estado === 'vivo' && estadoAnterior === 'caido') {
      cargarLista()
      if (seleccionada.value) cargarDetalle(seleccionada.value, false)
    }
    estadoAnterior = estado
  })
})

onUnmounted(() => {
  realtimeService
    .subscribePanel(slug.value)
    ?.unbind('whatsapp.conversacion', onConversacionActualizada)
  dejarDeObservar?.()
})

watch(slug, () => {
  cerrar()
  cargarLista()
})

const botPausadoHasta = computed(() =>
  detalle.value?.bot_pausado_hasta ? hora.format(new Date(detalle.value.bot_pausado_hasta)) : null,
)
</script>

<template>
  <div
    class="h-full overflow-hidden bg-neutral-primary"
    :class="
      compacta ? 'flex flex-col' : 'grid rounded-xl border border-default md:grid-cols-[20rem_1fr]'
    "
  >
    <!-- Lista (RN-55) -->
    <aside
      class="min-h-0 overflow-y-auto border-default"
      :class="
        compacta
          ? seleccionada
            ? 'hidden'
            : 'block flex-1'
          : ['md:border-r', seleccionada ? 'hidden md:block' : 'block']
      "
      aria-label="Lista de conversaciones"
    >
      <p v-if="cargandoLista" class="p-4 text-sm text-body">Cargando…</p>
      <div v-else-if="errorLista" class="p-4">
        <UiAlert variant="error">{{ errorLista }}</UiAlert>
      </div>
      <p v-else-if="conversaciones.length === 0" class="p-4 text-sm text-body">
        Todavía no hay conversaciones.
      </p>
      <ul v-else>
        <li v-for="c in conversaciones" :key="c.id_conversacion">
          <button
            type="button"
            class="flex w-full flex-col gap-1 border-b border-default px-4 py-3 text-left hover:bg-black/[0.03]"
            :class="seleccionada === c.id_conversacion ? 'bg-accent/10' : ''"
            :aria-current="seleccionada === c.id_conversacion ? 'true' : undefined"
            @click="abrir(c.id_conversacion)"
          >
            <span class="flex items-center gap-2">
              <span class="truncate font-semibold text-heading">{{ c.nombre }}</span>
              <UiBadge v-if="c.pide_persona" text="Pide persona" color="red" />
              <span v-if="c.ultimo_mensaje" class="ml-auto shrink-0 text-xs text-body">
                {{ cuando(c.ultimo_mensaje.enviado_en) }}
              </span>
            </span>
            <span class="truncate text-sm text-body">{{ vistaPrevia(c.ultimo_mensaje) }}</span>
          </button>
        </li>
      </ul>
    </aside>

    <!-- Conversación abierta (RN-56 a RN-58) -->
    <section
      class="min-h-0 flex-col"
      :class="
        compacta
          ? seleccionada
            ? 'flex flex-1'
            : 'hidden'
          : seleccionada
            ? 'flex'
            : 'hidden md:flex'
      "
      aria-label="Conversación"
    >
      <p v-if="!seleccionada" class="m-auto text-sm text-body">Elige una conversación.</p>
      <p v-else-if="cargandoDetalle" class="m-auto text-sm text-body">Cargando…</p>
      <div v-else-if="errorDetalle" class="p-4">
        <UiAlert variant="error">{{ errorDetalle }}</UiAlert>
      </div>

      <template v-else-if="detalle">
        <header class="flex flex-wrap items-center gap-2 border-b border-default px-4 py-3">
          <button
            type="button"
            class="rounded-lg px-2 py-1 text-sm text-body hover:bg-black/5"
            :class="compacta ? '' : 'md:hidden'"
            @click="cerrar"
          >
            ← Volver
          </button>
          <div class="min-w-0">
            <p class="truncate font-semibold text-heading">{{ detalle.nombre }}</p>
            <p v-if="detalle.telefono" class="text-xs text-body">+{{ detalle.telefono }}</p>
          </div>
          <span
            v-for="p in detalle.pedidos"
            :key="p.id_pedido"
            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700"
          >
            {{ p.numero_pedido }} · {{ p.estado }}
          </span>
          <div class="ml-auto flex items-center gap-2">
            <UiBadge v-if="detalle.pide_persona" text="Pide persona" color="red" />
            <template v-if="botPausadoHasta">
              <span class="text-xs text-body">Bot en pausa hasta las {{ botPausadoHasta }}</span>
              <UiButton
                variant="secondary"
                :full-width="false"
                :disabled="devolviendo"
                @click="devolverAlBot"
              >
                Devolver al bot
              </UiButton>
            </template>
          </div>
        </header>

        <div ref="historialRef" class="flex-1 space-y-2 overflow-y-auto bg-black/[0.02] px-4 py-4">
          <div
            v-for="m in detalle.mensajes"
            :key="m.id_mensaje"
            class="flex"
            :class="m.autor === 'cliente' ? 'justify-start' : 'justify-end'"
          >
            <div
              class="max-w-[80%] rounded-2xl px-3 py-2 text-sm shadow-sm"
              :class="{
                'bg-white text-heading': m.autor === 'cliente',
                'bg-emerald-50 text-heading': m.autor === 'bot',
                'bg-accent text-white': m.autor === 'persona',
                'bg-amber-50 text-heading': m.autor === 'aviso',
              }"
            >
              <p
                class="mb-0.5 text-[11px] font-semibold uppercase tracking-wide"
                :class="m.autor === 'persona' ? 'text-white/80' : 'text-body'"
              >
                {{
                  m.autor === 'persona' && m.nombre_usuario
                    ? m.nombre_usuario
                    : autorEtiqueta[m.autor]
                }}
                <span v-if="m.folio"> · {{ m.folio }}</span>
              </p>
              <p v-if="m.borrado" class="italic opacity-70">(texto borrado)</p>
              <a
                v-else-if="m.mapa_url"
                :href="m.mapa_url"
                target="_blank"
                rel="noopener"
                class="underline"
              >
                📍 Ver ubicación en el mapa
              </a>
              <p v-else-if="m.boton" class="whitespace-pre-line">🔘 {{ m.texto ?? m.boton }}</p>
              <p v-else-if="m.texto" class="whitespace-pre-line break-words">{{ m.texto }}</p>
              <p v-else class="italic opacity-70">({{ m.tipo }})</p>
              <p
                class="mt-1 text-right text-[11px]"
                :class="m.autor === 'persona' ? 'text-white/70' : 'text-body'"
              >
                {{ cuando(m.enviado_en) }}
                <span v-if="m.estado_envio === 'failed'" class="font-semibold text-red-600">
                  · no entregado</span
                >
              </p>
            </div>
          </div>
        </div>

        <!-- RN-57: solo dentro de las 24 horas -->
        <form class="border-t border-default p-3" @submit.prevent="enviar">
          <UiAlert v-if="!detalle.ventana_abierta" variant="warning">
            Pasaron más de 24 horas desde el último mensaje del cliente. WhatsApp no deja escribirle
            hasta que vuelva a escribir.
          </UiAlert>
          <template v-else>
            <UiAlert v-if="errorEnvio" variant="error" class="mb-2">{{ errorEnvio }}</UiAlert>
            <div class="flex items-end gap-2">
              <label :for="idRespuesta" class="sr-only">Respuesta</label>
              <textarea
                :id="idRespuesta"
                v-model="respuesta"
                rows="2"
                maxlength="4096"
                placeholder="Escribe una respuesta… (Enter envía, Shift+Enter cambia de línea)"
                class="min-h-[2.75rem] flex-1 resize-none rounded-lg border border-default px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent"
                :disabled="enviando"
                @keydown="onTeclaRespuesta"
              />
              <UiButton type="submit" :full-width="false" :disabled="enviando || !respuesta.trim()">
                {{ enviando ? 'Enviando…' : 'Enviar' }}
              </UiButton>
            </div>
            <p class="mt-1 text-xs text-body">
              Al contestar, el bot se pausa 2 horas en esta conversación.
            </p>
          </template>
        </form>
      </template>
    </section>
  </div>
</template>
