import { computed, ref } from 'vue'
import { plataformaDe, type Plataforma } from '../lib/instalacion'
import { useUiStore } from '../stores/ui'

interface EventoInstalar extends Event {
  prompt(): Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

// Chrome lo lanza una sola vez por carga, así que se escucha desde que arranca la app.
const eventoInstalar = ref<EventoInstalar | null>(null)
const recienInstalada = ref(false)
if (typeof window !== 'undefined') {
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault()
    eventoInstalar.value = e as EventoInstalar
  })
  window.addEventListener('appinstalled', () => {
    eventoInstalar.value = null
    recienInstalada.value = true
  })
}

const plataforma: Plataforma =
  typeof navigator === 'undefined'
    ? 'otra'
    : plataformaDe(navigator.userAgent, navigator.maxTouchPoints ?? 0)

/**
 * Solo en desarrollo: `?navegador=1` deja revisar las pantallas en la computadora sin instalar. En el
 * build de producción `import.meta.env.DEV` es falso y este salto no existe.
 */
function saltoDeDesarrollo(): boolean {
  if (!import.meta.env.DEV) return false
  try {
    if (new URLSearchParams(window.location.search).has('navegador')) {
      sessionStorage.setItem('pide:dev:navegador', 'si')
    }
    return sessionStorage.getItem('pide:dev:navegador') === 'si'
  } catch {
    return false
  }
}

/** ¿La app está abierta desde su ícono (instalada)? Sin esto no se entra (D7). */
export function instalada(): boolean {
  const modo = (m: string) => window.matchMedia?.(`(display-mode: ${m})`).matches === true
  return (
    modo('standalone') ||
    modo('fullscreen') ||
    modo('minimal-ui') ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true ||
    saltoDeDesarrollo()
  )
}

/**
 * Instalación obligatoria (spec tenant/035, D7) y permiso de avisos (RN-50).
 *
 * - En el navegador la app solo muestra la bienvenida con "Instalar".
 * - Android: el botón abre el aviso de instalación del teléfono, si el navegador lo permite.
 * - iPhone: no hay forma de instalar con un botón; se muestra la guía de 3 pasos.
 */
export function useInstalacion() {
  const ui = useUiStore()

  const puedeInstalarConBoton = computed(() => eventoInstalar.value !== null)
  const esIphone = plataforma === 'iphone'

  /** Abre el aviso de instalación de Android. Sin él, la pantalla muestra la guía. */
  async function instalar(): Promise<'aceptada' | 'rechazada' | 'sin-boton'> {
    const evento = eventoInstalar.value
    if (!evento) return 'sin-boton'
    await evento.prompt()
    const { outcome } = await evento.userChoice
    eventoInstalar.value = null
    return outcome === 'accepted' ? 'aceptada' : 'rechazada'
  }

  function puedePedirAvisos(): boolean {
    if (typeof Notification === 'undefined') return false
    return Notification.permission === 'default'
  }

  /** RN-50: después de registrarse. */
  function pedirAvisosSiToca(): void {
    if (puedePedirAvisos()) ui.hojaAvisos = true
  }

  async function activarAvisos(): Promise<NotificationPermission | null> {
    ui.hojaAvisos = false
    if (typeof Notification === 'undefined') return null
    return Notification.requestPermission()
  }

  return {
    plataforma,
    esIphone,
    recienInstalada,
    puedeInstalarConBoton,
    instalar,
    pedirAvisosSiToca,
    activarAvisos,
  }
}
