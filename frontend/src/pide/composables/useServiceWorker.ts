import { ref } from 'vue'

/**
 * Registro del service worker de la flotilla y aviso de versión nueva (spec tenant/035, RN-06 y
 * RN-07). Estado a nivel de módulo: hay un solo registro por página.
 */
export const hayVersionNueva = ref(false)

let enEspera: ServiceWorker | null = null

export async function registrarServiceWorker(slug: string): Promise<void> {
  if (!('serviceWorker' in navigator)) return

  try {
    // Un solo archivo para todas las flotillas; el alcance propio hace que cada una sea una app aparte.
    const registro = await navigator.serviceWorker.register('/pide/sw.js', {
      scope: `/pide/${slug}/`,
    })

    const vigilar = (trabajador: ServiceWorker | null) => {
      if (!trabajador) return
      const revisar = () => {
        // Solo es "nueva" si ya había una versión controlando la página; la primera instalación no avisa.
        if (trabajador.state === 'installed' && navigator.serviceWorker.controller) {
          enEspera = trabajador
          hayVersionNueva.value = true
        }
      }
      revisar()
      trabajador.addEventListener('statechange', revisar)
    }

    vigilar(registro.waiting)
    registro.addEventListener('updatefound', () => vigilar(registro.installing))

    // Al volver a la app después de un rato, buscar si hay versión nueva.
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') void registro.update().catch(() => {})
    })
  } catch {
    // Sin service worker la app funciona igual; solo no se puede instalar ni avisar de versiones.
  }
}

/** "Actualizar": activa la versión en espera y recarga cuando tome el control. */
export function actualizarAhora(): void {
  if (!enEspera) {
    window.location.reload()
    return
  }
  navigator.serviceWorker.addEventListener('controllerchange', () => window.location.reload(), {
    once: true,
  })
  enEspera.postMessage('SALTAR_ESPERA')
}
