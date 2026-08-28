import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import http, { ensureCsrfCookie } from '@/lib/http'

export interface AdminCentral {
  id_admin: number
  nombre: string
  apellido_paterno: string
  apellido_materno: string | null
  email: string
  estado: string
  rol: string | null
  ultimo_acceso: string | null
}

interface ResetPasswordPayload {
  token: string
  email: string
  password: string
  password_confirmation: string
}

export const useAdminAuthStore = defineStore('adminAuth', () => {
  const admin = ref<AdminCentral | null>(null)
  const checked = ref(false)

  const isAuthenticated = computed(() => admin.value !== null)

  async function fetchMe(): Promise<boolean> {
    try {
      const { data } = await http.get<AdminCentral>('/admin/me')
      admin.value = data
      return true
    } catch {
      admin.value = null
      return false
    } finally {
      checked.value = true
    }
  }

  async function login(email: string, password: string): Promise<void> {
    await ensureCsrfCookie()
    const { data } = await http.post<AdminCentral>('/admin/login', { email, password })
    admin.value = data
    checked.value = true
  }

  async function logout(): Promise<void> {
    await http.post('/admin/logout')
    admin.value = null
  }

  async function forgotPassword(email: string): Promise<void> {
    await ensureCsrfCookie()
    await http.post('/admin/forgot-password', { email })
  }

  async function resetPassword(payload: ResetPasswordPayload): Promise<void> {
    await ensureCsrfCookie()
    await http.post('/admin/reset-password', payload)
  }

  return {
    admin,
    checked,
    isAuthenticated,
    fetchMe,
    login,
    logout,
    forgotPassword,
    resetPassword,
  }
})
