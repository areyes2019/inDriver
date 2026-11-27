/* global self, caches, fetch, URL */
/**
 * Service worker de la app de clientes (spec tenant/035, RN-06 a RN-08).
 *
 * Plantilla: el build la escribe en `/pide/sw.js` con la versión del despliegue en `VERSION`, que es
 * lo que hace que el teléfono note la versión nueva. Uno solo para todas las flotillas; cada flotilla lo
 * registra con su propio alcance (`/pide/{slug}/`).
 *
 * - Una versión nueva espera: no reemplaza a la vieja a media sesión. La app ofrece "Actualizar" y,
 *   al tocarlo, manda `SALTAR_ESPERA` (RN-07).
 * - Solo guarda los archivos estáticos con huella en el nombre. Nunca respuestas de `/api`, tokens ni
 *   datos del cliente (RN-08). Sin internet, la app no funciona: no es una app offline.
 */
const VERSION = '__VERSION__'
const CACHE = `pide-estaticos-${VERSION}`
const ESTATICO = /^\/assets\/[^/]+-[A-Za-z0-9_-]{8,}\.(?:js|css|woff2?|png|svg|webp)$/

self.addEventListener('install', () => {
  // Sin skipWaiting: la versión nueva espera a que el cliente acepte (RN-07).
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const llaves = await caches.keys()
      await Promise.all(
        llaves
          .filter((llave) => llave.startsWith('pide-estaticos-') && llave !== CACHE)
          .map((llave) => caches.delete(llave)),
      )
      await self.clients.claim()
    })(),
  )
})

self.addEventListener('message', (event) => {
  if (event.data === 'SALTAR_ESPERA') self.skipWaiting()
})

self.addEventListener('fetch', (event) => {
  const peticion = event.request
  if (peticion.method !== 'GET') return

  const url = new URL(peticion.url)
  if (url.origin !== self.location.origin || !ESTATICO.test(url.pathname)) return

  event.respondWith(
    (async () => {
      const cache = await caches.open(CACHE)
      const guardada = await cache.match(peticion)
      if (guardada) return guardada

      const respuesta = await fetch(peticion)
      if (respuesta.ok) await cache.put(peticion, respuesta.clone())
      return respuesta
    })(),
  )
})
