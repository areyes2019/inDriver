import { useRouter } from 'vue-router'
import { api } from '../api'
import type { DatosRegistro } from '../api/tipos'
import { useMarcaStore } from '../stores/marca'
import { useUiStore } from '../stores/ui'

/**
 * Lo que se está verificando, entre la pantalla de registro o entrar y la del código. Vive en
 * memoria y no en la URL: el nombre y el teléfono no tienen por qué quedar en el historial.
 */
let pendiente: { email: string; registro: DatosRegistro | null } | null = null

export function verificacionPendiente() {
  return pendiente
}

export type ResultadoPedir = 'ok' | 'no-encontrado' | 'correo-invalido' | 'error'

/** Pedir el código por correo (RN-10 a RN-12, RN-15). Compartido por registro, entrar y reenviar. */
export function useCodigo() {
  const router = useRouter()
  const marca = useMarcaStore()
  const ui = useUiStore()

  async function pedir(
    email: string,
    registro: DatosRegistro | null,
    irAlCodigo = true,
  ): Promise<ResultadoPedir> {
    const r = await api.pedirCodigo(marca.slug, email, registro)
    if (r.ok) {
      pendiente = { email, registro }
      if (irAlCodigo) await router.push({ name: 'codigo', params: { slug: marca.slug } })
      return 'ok'
    }
    switch (r.motivo) {
      case 'NO_ENCONTRADO':
        return 'no-encontrado'
      case 'CORREO_INVALIDO':
        return 'correo-invalido'
      case 'LIMITE':
        ui.avisar('Pediste muchos códigos. Espera un momento e intenta de nuevo.', 'error')
        return 'error'
      case 'NO_DISPONIBLE':
        await router.replace({ name: 'no-disponible', params: { slug: marca.slug } })
        return 'error'
      case 'NO_SE_PUDO_ENVIAR':
        ui.avisar('No pudimos mandar el correo. Intenta de nuevo en un momento.', 'error')
        return 'error'
    }
  }

  return { pedir }
}

/** Formato básico; el servidor además revisa que el dominio reciba correo (RN-10). */
export function esCorreoValido(texto: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(texto.trim())
}

export function normalizarCorreo(texto: string): string {
  return texto.trim().toLowerCase()
}
