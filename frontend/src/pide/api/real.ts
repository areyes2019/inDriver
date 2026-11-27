import type { ApiCliente } from './cliente'
import { apiSimulada } from './mock'
import type { Cliente, Marca, ResultadoCodigo, ResultadoVerificar } from './tipos'
import { useMarcaStore } from '../stores/marca'

/**
 * API real de la app de clientes (spec tenant/035, sección 8). Va entrando por fases: desde la
 * Fase 3, marca, código, verificación, salir y perfil. Lo demás sigue en la simulada hasta su fase.
 *
 * Usa `fetch` y no el `http` (axios) del Panel: la app autentica con token Bearer, no con la cookie
 * de sesión, y así no descarga el código del Panel.
 */

const API_URL: string = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api/v1'

/** La sesión ya no vale (token revocado, cliente inactivo o de otra flotilla). */
export class SesionVencida extends Error {}

interface Respuesta<T> {
  estado: number
  datos: T
}

async function llamar<T>(
  slug: string,
  ruta: string,
  opciones: { metodo?: string; cuerpo?: unknown; conToken?: boolean } = {},
): Promise<Respuesta<T>> {
  const encabezados: Record<string, string> = { Accept: 'application/json' }
  if (opciones.cuerpo !== undefined) encabezados['Content-Type'] = 'application/json'
  if (opciones.conToken) {
    const token = useMarcaStore().almacen.leer('token')
    if (token) encabezados.Authorization = `Bearer ${token}`
  }

  const respuesta = await fetch(`${API_URL}/t/${encodeURIComponent(slug)}/cliente${ruta}`, {
    method: opciones.metodo ?? 'GET',
    headers: encabezados,
    body: opciones.cuerpo === undefined ? undefined : JSON.stringify(opciones.cuerpo),
    credentials: 'omit',
  })

  if (opciones.conToken && respuesta.status === 401) throw new SesionVencida()

  const texto = await respuesta.text()
  const datos = (texto ? JSON.parse(texto) : null) as T
  return { estado: respuesta.status, datos }
}

function slugActual(): string {
  return useMarcaStore().slug
}

interface ClienteApi {
  id_cliente: number
  nombre: string
  telefono: string | null
  email: string
}

function aCliente(c: ClienteApi): Cliente {
  return { id: c.id_cliente, nombre: c.nombre, telefono: c.telefono ?? '', email: c.email }
}

interface MarcaApi {
  slug: string
  nombre: string
  logo_url: string | null
  banner_url: string | null
  telefono_contacto: string | null
  servicios: { enviar: boolean; recibir: boolean }
  disponible: boolean
  centro: { lat: number; lng: number }
  horario: string
  abierto_ahora: boolean
}

export const apiReal: ApiCliente = {
  ...apiSimulada,

  async marca(slug) {
    const { estado, datos } = await llamar<MarcaApi>(slug, '/marca')
    if (estado !== 200) throw new Error(`No se pudo cargar la flotilla (${estado})`)
    const marca: Marca = {
      slug: datos.slug,
      nombre: datos.nombre,
      logoUrl: datos.logo_url,
      bannerUrl: datos.banner_url,
      telefonoContacto: datos.telefono_contacto,
      servicios: datos.servicios,
      disponible: datos.disponible,
      centro: datos.centro,
      horario: datos.horario,
      abiertoAhora: datos.abierto_ahora,
    }
    return marca
  },

  async pedirCodigo(slug, email, registro): Promise<ResultadoCodigo> {
    const { estado, datos } = await llamar<{ motivo?: string }>(slug, '/codigo', {
      metodo: 'POST',
      cuerpo: { email, modo: registro ? 'registro' : 'entrar' },
    })
    if (estado === 200) return { ok: true }
    if (estado === 404) return { ok: false, motivo: 'NO_ENCONTRADO' }
    if (estado === 429) return { ok: false, motivo: 'LIMITE' }
    if (estado === 403) return { ok: false, motivo: 'NO_DISPONIBLE' }
    if (estado === 422) return { ok: false, motivo: 'CORREO_INVALIDO' }
    void datos
    return { ok: false, motivo: 'NO_SE_PUDO_ENVIAR' }
  },

  async verificar(slug, email, codigo, registro): Promise<ResultadoVerificar> {
    const { estado, datos } = await llamar<{
      token?: string
      cliente?: ClienteApi
      nuevo?: boolean
      motivo?: string
    }>(slug, '/verificar', {
      metodo: 'POST',
      cuerpo: { email, codigo, ...(registro ?? {}) },
    })
    if (estado === 200 && datos.token && datos.cliente) {
      return {
        ok: true,
        token: datos.token,
        cliente: aCliente(datos.cliente),
        nuevo: !!datos.nuevo,
      }
    }
    if (estado === 429) return { ok: false, motivo: 'BLOQUEADO' }
    if (estado === 403) return { ok: false, motivo: 'CLIENTE_INACTIVO' }
    return {
      ok: false,
      motivo: datos?.motivo === 'CODIGO_VENCIDO' ? 'CODIGO_VENCIDO' : 'CODIGO_INCORRECTO',
    }
  },

  async salir() {
    try {
      await llamar(slugActual(), '/salir', { metodo: 'POST', conToken: true })
    } catch {
      // Si el token ya no servía, la sesión ya estaba cerrada del lado del servidor.
    }
  },

  async perfil() {
    const { estado, datos } = await llamar<ClienteApi>(slugActual(), '/perfil', { conToken: true })
    if (estado !== 200) throw new SesionVencida()
    return aCliente(datos)
  },

  async actualizarPerfil(cambios) {
    const { estado, datos } = await llamar<ClienteApi>(slugActual(), '/perfil', {
      metodo: 'PUT',
      cuerpo: cambios,
      conToken: true,
    })
    if (estado !== 200) throw new Error('No se pudieron guardar tus datos')
    return aCliente(datos)
  },
}
