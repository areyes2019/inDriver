import { computed, ref } from 'vue'
import { plataformaDe, type Plataforma } from '../lib/instalacion'
import { useMarcaStore } from '../stores/marca'
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

/** ¿La app está abierta desde su ícono (instalada) y no desde el navegador? */
export function instalada(): boolean {
  return (
    window.matchMedia?.('(display-mode: standalone)').matches ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true
  )
}

/**
 * Instalación (spec tenant/035, D7 corregida en 1.3) y permiso de avisos (RN-50).
 *
 * - Al abrir la liga sin la app instalada, la primera pantalla es la bienvenida con "Instalar".
 * - Android: el botón abre el aviso de instalación del teléfono, si el navegador lo permite.
 * - iPhone: no hay forma de instalar con un botón; se muestra la guía de 3 pasos.
 * - "Continuar en el navegador" salta la bienvenida. A quien la saltó se le recuerda una sola vez,
 *   después de su primer pedido.
 */
export function useInstalacion() {
  const marca = useMarcaStore()
  const ui = useUiStore()

  const puedeInstalarConBoton = computed(() => eventoInstalar.value !== null)
  const esIphone = plataforma === 'iphone'

  function eligioNavegador(): boolean {
    return marca.almacen.leer('continuar-navegador') === 'si'
  }

  function continuarEnNavegador(): void {
    marca.almacen.guardar('continuar-navegador', 'si')
  }

  /** Abre el aviso de instalación de Android. Sin él, la pantalla muestra la guía. */
  async function instalar(): Promise<'aceptada' | 'rechazada' | 'sin-boton'> {
    const evento = eventoInstalar.value
    if (!evento) return 'sin-boton'
    await evento.prompt()
    const { outcome } = await evento.userChoice
    eventoInstalar.value = null
    return outcome === 'accepted' ? 'aceptada' : 'rechazada'
  }

  /** Recordatorio único tras el primer pedido, solo a quien siguió en el navegador. */
  function recordarTrasPedido(): void {
    if (instalada() || !eligioNavegador()) return
    if (marca.almacen.leer('instalar-ofrecido') === 'recordado') return
    marca.almacen.guardar('instalar-ofrecido', 'recordado')
    ui.hojaInstalar = true
  }

  function cerrarInstalar(): void {
    ui.hojaInstalar = false
  }

  function puedePedirAvisos(): boolean {
    if (typeof Notification === 'undefined') return false
    if (Notification.permission !== 'default') return false
    return !esIphone || instalada()
  }

  /** RN-50: después de registrarse con la app ya instalada. */
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
    instalada,
    recienInstalada,
    puedeInstalarConBoton,
    eligioNavegador,
    continuarEnNavegador,
    instalar,
    recordarTrasPedido,
    cerrarInstalar,
    pedirAvisosSiToca,
    activarAvisos,
  }
}
