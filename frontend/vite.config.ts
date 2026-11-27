import { readFileSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'

import { defineConfig, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import tailwindcss from '@tailwindcss/vite'

/**
 * App de clientes (spec tenant/035): en desarrollo, cualquier ruta bajo /pide/ sirve `pide.html`,
 * igual que hará nginx en producción. Sin esto, el fallback del SPA del Panel respondería con su
 * `index.html` y la PWA nunca cargaría al recargar una pantalla interna.
 */
function fallbackPide(): Plugin {
  return {
    name: 'fallback-pide',
    configureServer(server) {
      server.middlewares.use((req, _res, next) => {
        const url = req.url ?? ''
        const esArchivo = /\.[a-z0-9]+(\?|$)/i.test(url)
        if (url.startsWith('/pide/') && !esArchivo) {
          req.url = '/pide.html'
        }
        next()
      })
    },
  }
}

/**
 * Service worker de la app de clientes (spec tenant/035, RN-06 y RN-07). Se escribe en `/pide/sw.js`
 * con una versión nueva en cada build: el teléfono compara el archivo byte por byte, así que sin ese
 * cambio nunca notaría que hay una versión nueva. Uno solo para todas las flotillas.
 */
function serviceWorkerPide(): Plugin {
  const plantilla = fileURLToPath(new URL('./src/pide/sw/sw.template.js', import.meta.url))
  const generar = (version: string) =>
    readFileSync(plantilla, 'utf8').replaceAll('__VERSION__', version)

  return {
    name: 'service-worker-pide',
    configureServer(server) {
      server.middlewares.use('/pide/sw.js', (_req, res) => {
        res.setHeader('Content-Type', 'application/javascript')
        res.setHeader('Cache-Control', 'no-cache')
        res.end(generar('dev'))
      })
    },
    generateBundle() {
      this.emitFile({
        type: 'asset',
        fileName: 'pide/sw.js',
        source: generar(new Date().toISOString()),
      })
    },
  }
}

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), vueDevTools(), tailwindcss(), fallbackPide(), serviceWorkerPide()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    rolldownOptions: {
      // Dos entradas que se compilan por separado: el cliente nunca descarga código del Panel.
      input: {
        main: fileURLToPath(new URL('./index.html', import.meta.url)),
        pide: fileURLToPath(new URL('./pide.html', import.meta.url)),
      },
    },
  },
})
