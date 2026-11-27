<script setup lang="ts">
import { computed, ref } from 'vue'
import http from '@/lib/http'
import UiAlert from '@/components/ui/UiAlert.vue'
import UiToggle from '@/components/ui/UiToggle.vue'
import ImagenMarcaApp from '@/components/tenant/ImagenMarcaApp.vue'
import { useTenantAuthStore } from '@/stores/tenantAuth'

/**
 * Interruptor de la app de clientes (spec tenant/035, D17). Solo AdminCliente. Apagada, la app de
 * esta flotilla muestra "Muy pronto" y no deja registrarse ni entrar.
 *
 * Abajo, el logo y el banner de la app (RN-60 y RN-61). Sin ellos, la app usa los de MotoPanda.
 */
const props = defineProps<{ slug: string }>()
const prendida = defineModel<boolean>({ required: true })
const logoUrl = defineModel<string | null>('logoUrl', { required: true })
const bannerUrl = defineModel<string | null>('bannerUrl', { required: true })

// El espectador de ADMIN_CENTRAL ve las imágenes pero no los botones: el backend lo rechazaría igual.
const auth = useTenantAuthStore()
const puedeEditar = computed(() => !auth.esEspectador)

const LOGO_MIN = 512
// Igual que el servidor: el logo puede salirse del cuadrado exacto hasta un 2 %.
const TOLERANCIA_CUADRADO = 0.02
const BANNER_ANCHO_MIN = 800

function revisarLogo(ancho: number, alto: number): string | null {
  if (Math.abs(ancho - alto) > Math.max(ancho, alto) * TOLERANCIA_CUADRADO) {
    return `El logo debe ser cuadrado (mide ${ancho} × ${alto} px).`
  }
  if (ancho < LOGO_MIN || alto < LOGO_MIN) {
    return `El logo debe medir al menos ${LOGO_MIN} × ${LOGO_MIN} px (mide ${ancho} × ${alto}).`
  }
  return null
}

function revisarBanner(ancho: number, alto: number): string | null {
  if (ancho <= alto) {
    return `El banner debe ser horizontal, más ancho que alto (mide ${ancho} × ${alto} px).`
  }
  if (ancho < BANNER_ANCHO_MIN) {
    return `El banner debe medir al menos ${BANNER_ANCHO_MIN} px de ancho (mide ${ancho}).`
  }
  return null
}

const guardando = ref(false)
const error = ref('')
const copiado = ref(false)

const enlace = computed(() => `${window.location.origin}/pide/${props.slug}/`)

async function cambiar(valor: boolean): Promise<void> {
  const anterior = prendida.value
  prendida.value = valor
  guardando.value = true
  error.value = ''
  try {
    await http.put(`/t/${props.slug}/configuracion/app-clientes`, {
      app_clientes: valor ? 'Si' : 'No',
    })
  } catch {
    prendida.value = anterior
    error.value = 'No se pudo cambiar. Intenta de nuevo.'
  } finally {
    guardando.value = false
  }
}

async function copiar(): Promise<void> {
  try {
    await navigator.clipboard.writeText(enlace.value)
    copiado.value = true
    setTimeout(() => (copiado.value = false), 2000)
  } catch {
    // Sin permiso de portapapeles: el enlace sigue visible para copiarlo a mano.
  }
}
</script>

<template>
  <div class="max-w-2xl space-y-5">
    <div class="flex items-start justify-between gap-6 rounded-lg border border-gray-200 p-4">
      <div>
        <p class="font-semibold text-heading">Recibir pedidos desde la app</p>
        <p class="mt-1 text-sm text-body">
          Tus clientes se registran con su correo y piden sus envíos desde el celular. Los pedidos
          llegan al Panel como cualquier otro.
        </p>
      </div>
      <UiToggle
        :model-value="prendida"
        :disabled="guardando"
        aria-label="Recibir pedidos desde la app"
        @update:model-value="cambiar"
      />
    </div>

    <UiAlert v-if="error" variant="error">{{ error }}</UiAlert>

    <div class="rounded-lg border border-gray-200 p-4">
      <p class="font-semibold text-heading">Enlace de tu app</p>
      <p class="mt-1 text-sm text-body">
        Compártelo con tus clientes. Mientras la app esté apagada, verán "Muy pronto".
      </p>
      <div class="mt-3 flex flex-wrap items-center gap-2">
        <code class="rounded bg-gray-50 px-3 py-2 text-sm text-heading">{{ enlace }}</code>
        <button
          type="button"
          class="rounded-lg border border-accent px-3 py-1.5 text-sm font-semibold text-accent transition-colors hover:bg-accent hover:text-white"
          @click="copiar"
        >
          {{ copiado ? 'Copiado' : 'Copiar' }}
        </button>
      </div>
    </div>

    <ImagenMarcaApp
      v-model="logoUrl"
      :slug="slug"
      tipo="logo"
      titulo="Logo de la app"
      descripcion="Aparece en el encabezado de la app y como ícono al instalarla en el celular."
      :especificaciones="[
        'Formato PNG o JPG. Si tu logo tiene fondo transparente, usa PNG.',
        'Cuadrado: el mismo ancho que alto.',
        'Mínimo 512 × 512 píxeles; recomendado 1024 × 1024.',
        'Peso máximo: 1 MB.',
        'La app lo muestra dentro de un círculo: deja un margen alrededor para que no se corte.',
        'El ícono de los celulares que ya instalaron la app cambia hasta que la vuelvan a instalar.',
      ]"
      :formatos="['image/png', 'image/jpeg']"
      :peso-max-mb="1"
      :revisar-medidas="revisarLogo"
      :editable="puedeEditar"
    />

    <ImagenMarcaApp
      v-model="bannerUrl"
      :slug="slug"
      tipo="banner"
      titulo="Banner de la app"
      descripcion="La imagen grande de la pantalla de bienvenida. Úsala para tu marca o una promoción."
      :especificaciones="[
        'Formato PNG, JPG o WebP.',
        'Horizontal: más ancho que alto.',
        'Mínimo 800 píxeles de ancho; recomendado 1200 × 540 (proporción 20:9).',
        'Peso máximo: 2 MB.',
        'Se ajusta al ancho del celular y puede recortar un poco los bordes: no pongas texto pegado a la orilla.',
      ]"
      :formatos="['image/png', 'image/jpeg', 'image/webp']"
      :peso-max-mb="2"
      :revisar-medidas="revisarBanner"
      :editable="puedeEditar"
    />
  </div>
</template>
