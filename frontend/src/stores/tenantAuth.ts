import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { isAxiosError } from 'axios'
import http, { ensureCsrfCookie } from '@/lib/http'

export interface CiudadAsignada {
  id_ciudad: number
  nombre: string
  place_id: string
  lat: number
  lng: number
  bounds: { north: number; south: number; east: number; west: number } | null
}

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
  ciudades: CiudadAsignada[]
  ciudades_tenant: CiudadAsignada[]
  cobertura_bounds: { north: number; south: number; east: number; west: number } | null
  usar_despachadores: 'Sí' | 'No'
}

interface ResetPasswordPayload {
  token: string
  email: string
  password: string
  password_confirmation: string
}

interface ChangePasswordPayload {
  password_actual: string
  password: string
  password_confirmation: string
}

export const useTenantAuthStore = defineStore('tenantAuth', () => {
  const usuario = ref<UsuarioTenant | null>(null)
  const checked = ref(false)
  const slug = ref('')
  // Si el slug de la URL corresponde a un tenant real. Con el slug pegado al dominio cualquier
  // URL mal escrita cae en la entrada del tenant, así que "negocio inexistente" y "sin sesión"
  // dejan de verse iguales (spec tenant/001, enmienda "el slug va inmediatamente después del
  // dominio", RN-S7). Lo distingue el 404 de `IdentificarTenantPorSlug`.
  const tenantExiste = ref(true)

  const isAuthenticated = computed(() => usuario.value !== null)

  // El "operativo" del tenant —quien crea envíos y ve el Panel— depende de la configuración, no
  // solo del rol: Despachador cuando el tenant usa despachadores, AdminCliente cuando no (spec
  // tenant/011), nunca ambos a la vez. Vive aquí, y no en el router ni en el layout, porque el
  // login también lo necesita para decidir a dónde aterrizar (spec tenant/007, RN-A1): tres copias
  // de la misma regla eran una invitación a que un día una dijera otra cosa.
  const esOperativo = computed(() => {
    const rol = usuario.value?.rol
    const usaDespachadores = usuario.value?.usar_despachadores === 'Sí'

    return (
      (rol === 'Despachador' && usaDespachadores) || (rol === 'AdminCliente' && !usaDespachadores)
    )
  })

  async function fetchMe(currentSlug: string): Promise<boolean> {
    slug.value = currentSlug
    tenantExiste.value = true
    try {
      const { data } = await http.get<UsuarioTenant>(`/t/${currentSlug}/me`)
      usuario.value = data
      return true
    } catch (e) {
      usuario.value = null
      if (isAxiosError(e) && e.response?.status === 404) {
        tenantExiste.value = false
      }
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

  async function changePassword(
    currentSlug: string,
    payload: ChangePasswordPayload,
  ): Promise<void> {
    await http.post(`/t/${currentSlug}/cambiar-password`, payload)
  }

  return {
    usuario,
    checked,
    slug,
    tenantExiste,
    isAuthenticated,
    esOperativo,
    fetchMe,
    login,
    logout,
    forgotPassword,
    resetPassword,
    changePassword,
  }
})
