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

export function ensureCsrfCookie() {
  return axios.get(`${APP_URL}/sanctum/csrf-cookie`, { withCredentials: true })
}

export default http
