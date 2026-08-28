import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import http, { ensureCsrfCookie } from '@/lib/http'

export interface UsuarioTenant {
  id_usuario: number
  nombre: string
  apellido_paterno: string
  apellido_materno: string | null
  telefono: string | null
  email: string
  rol: string
  estado: string
  ultimo_acceso: string | null
}

interface ResetPasswordPayload {
  token: string
  email: string
  password: string
  password_confirmation: string
}

export const useTenantAuthStore = defineStore('tenantAuth', () => {
  const usuario = ref<UsuarioTenant | null>(null)
  const checked = ref(false)
  const slug = ref('')

  const isAuthenticated = computed(() => usuario.value !== null)

  async function fetchMe(currentSlug: string): Promise<boolean> {
    slug.value = currentSlug
    try {
      const { data } = await http.get<UsuarioTenant>(`/t/${currentSlug}/me`)
      usuario.value = data
      return true
    } catch {
      usuario.value = null
      return false
    } finally {
      checked.value = true
    }
  }

  async function login(currentSlug: string, email: string, password: string): Promise<void> {
    slug.value = currentSlug
    await ensureCsrfCookie()
    const { data } = await http.post<UsuarioTenant>(`/t/${currentSlug}/login`, {
      email,
      password,
    })
    usuario.value = data
    checked.value = true
  }

  async function logout(): Promise<void> {
    await http.post(`/t/${slug.value}/logout`)
    usuario.value = null
  }

  async function forgotPassword(currentSlug: string, email: string): Promise<void> {
    await ensureCsrfCookie()
    await http.post(`/t/${currentSlug}/forgot-password`, { email })
  }

  async function resetPassword(currentSlug: string, payload: ResetPasswordPayload): Promise<void> {
    await ensureCsrfCookie()
    await http.post(`/t/${currentSlug}/reset-password`, payload)
  }

  return {
    usuario,
    checked,
    slug,
    isAuthenticated,
    fetchMe,
    login,
    logout,
    forgotPassword,
    resetPassword,
  }
})
