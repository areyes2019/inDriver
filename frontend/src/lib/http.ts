import axios from 'axios'

export const API_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api/v1'
const APP_URL = API_URL.replace(/\/api\/v1\/?$/, '')

const http = axios.create({
  baseURL: API_URL,
  withCredentials: true,
  withXSRFToken: true,
})

export function ensureCsrfCookie() {
  return axios.get(`${APP_URL}/sanctum/csrf-cookie`, { withCredentials: true })
}

export default http
