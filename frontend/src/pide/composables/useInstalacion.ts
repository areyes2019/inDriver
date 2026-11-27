import { computed, ref } from 'vue'
import { useMarcaStore } from '../stores/marca'
import { useUiStore } from '../stores/ui'

interface EventoInstalar extends Event {
  prompt(): Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

// Chrome lo lanza una sola vez por carga, así que se escucha desde que arranca la app.
const eventoInstalar = ref<EventoInstalar | null>(null)
if (typeof window !== 'undefined') {
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault()
    eventoInstalar.value = e as EventoInstalar
  })
}

const esIphone = typeof navigator !== 'undefined' && /iphone|ipad|ipod/i.test(navigator.userAgent)

function instalada(): boolean {
  return (
    window.matchMedia?.('(display-mode: standalone)').matches ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true
  )
}

/**
 * Invitación a instalar (D7) y permiso de avisos (RN-50).
 *
 * - Se ofrece después del registro. Si dice "Ahora no", una vez más tras su primer pedido y ya no.
 * - En iPhone no hay botón de instalar: se muestra una guía.
 * - El permiso de avisos se pide después, y en iPhone solo con la app instalada.
 */
export function useInstalacion() {
  const marca = useMarcaStore()
  const ui = useUiStore()

  const puedeInstalarConBoton = computed(() => eventoInstalar.value !== null)

  function ofrecerSiToca(momento: 'registro' | 'pedido'): void {
    if (instalada()) {
      pedirAvisosSiToca()
      return
    }
    const estado = marca.almacen.leer('instalar-ofrecido')
    if (momento === 'registro' && estado === null) {
      marca.almacen.guardar('instalar-ofrecido', 'registro')
      ui.hojaInstalar = true
    } else if (momento === 'pedido' && estado === 'registro') {
      marca.almacen.guardar('instalar-ofrecido', 'listo')
      ui.hojaInstalar = true
    }
  }

  async function instalar(): Promise<void> {
    const evento = eventoInstalar.value
    if (!evento) return
    await evento.prompt()
    const { outcome } = await evento.userChoice
    eventoInstalar.value = null
    if (outcome === 'accepted') marca.almacen.guardar('instalar-ofrecido', 'listo')
    cerrarInstalar()
  }

  function cerrarInstalar(): void {
    ui.hojaInstalar = false
    pedirAvisosSiToca()
  }

  function puedePedirAvisos(): boolean {
    if (typeof Notification === 'undefined') return false
    if (Notification.permission !== 'default') return false
    return !esIphone || instalada()
  }

  function pedirAvisosSiToca(): void {
    if (puedePedirAvisos()) ui.hojaAvisos = true
  }

  async function activarAvisos(): Promise<NotificationPermission | null> {
    ui.hojaAvisos = false
    if (typeof Notification === 'undefined') return null
    return Notification.requestPermission()
  }

  return {
    esIphone,
    instalada,
    puedeInstalarConBoton,
    ofrecerSiToca,
    instalar,
    cerrarInstalar,
    activarAvisos,
  }
}
