import './pide.css'
import '@/lib/icons'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import PideApp from './PideApp.vue'
import router from './router'
import { registrarServiceWorker } from './composables/useServiceWorker'
import { slugDeRuta } from './lib/instalacion'

const API_URL: string = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api/v1'

/**
 * El manifiesto es de cada flotilla (RN-05), así que se pone antes de montar la app: Chrome lo lee
 * al cargar para decidir si ofrece instalarla.
 */
function prepararInstalacion(slug: string): void {
  const manifiesto = document.createElement('link')
  manifiesto.rel = 'manifest'
  manifiesto.href = `${API_URL}/t/${encodeURIComponent(slug)}/cliente/manifest.webmanifest`
  document.head.appendChild(manifiesto)
}

const slug = slugDeRuta(window.location.pathname)
if (slug) prepararInstalacion(slug)

createApp(PideApp).use(createPinia()).use(router).mount('#pide')

if (slug) void registrarServiceWorker(slug)
