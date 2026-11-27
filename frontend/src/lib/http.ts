import axios from 'axios'

export const API_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api/v1'
const APP_URL = API_URL.replace(/\/api\/v1\/?$/, '')

// Contra qué backend corre este build. `.env.production` gana sobre `.env.local` en `vite build`,
// así que el Panel compilado apunta al VPS aunque exista un `.env.local` de desarrollo: dejarlo en
// la consola evita el diagnóstico ciego de "la app dice una cosa y el Panel otra" cuando en
// realidad son dos entornos (y dos tenants) distintos.
console.info(`[API] ${API_URL}`)

const http = axios.create({
  baseURL: API_URL,
  withCredentials: true,
  withXSRFToken: true,
})

/** Código con el que el backend rechaza una escritura hecha en modo espectador. */
export const CODIGO_ESPECTADOR = 'ESPECTADOR_SOLO_LECTURA'
export const EVENTO_ESPECTADOR_BLOQUEADO = 'espectador-bloqueado'

// Modo espectador de ADMIN_CENTRAL: cualquier acción que el Panel intente se rechaza en el backend.
// Además del error que maneje cada pantalla, se avisa una vez al layout para que lo diga claro.
http.interceptors.response.use(undefined, (error) => {
  if (error?.response?.status === 403 && error.response.data?.code === CODIGO_ESPECTADOR) {
    window.dispatchEvent(new CustomEvent(EVENTO_ESPECTADOR_BLOQUEADO))
  }
  return Promise.reject(error)
})

export function ensureCsrfCookie() {
  return axios.get(`${APP_URL}/sanctum/csrf-cookie`, { withCredentials: true })
}

export default http
