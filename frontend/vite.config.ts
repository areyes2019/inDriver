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

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), vueDevTools(), tailwindcss(), fallbackPide()],
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
