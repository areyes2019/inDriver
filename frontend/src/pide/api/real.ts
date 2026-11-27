import type { ApiCliente } from './cliente'
import { apiSimulada } from './mock'
import type {
  Cliente,
  Direccion,
  Marca,
  ResultadoCodigo,
  ResultadoCotizar,
  ResultadoVerificar,
} from './tipos'
import { useMarcaStore } from '../stores/marca'

/**
 * API real de la app de clientes (spec tenant/035, sección 8). Va entrando por fases:
 * - Fase 3: marca, código, verificación, salir y perfil.
 * - Fase 4: zona de servicio, dirección de un punto, cotización y direcciones favoritas.
 * Lo demás sigue en la simulada hasta su fase.
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

  async validarPunto(punto) {
    const { estado, datos } = await llamar<{ cubre: boolean }>(slugActual(), '/zona', {
      metodo: 'POST',
      cuerpo: { lat: punto.lat, lng: punto.lng },
      conToken: true,
    })
    return estado === 200 && datos.cubre
  },

  async direccionDe(punto) {
    const { estado, datos } = await llamar<{ direccion: string }>(slugActual(), '/direccion', {
      metodo: 'POST',
      cuerpo: { lat: punto.lat, lng: punto.lng },
      conToken: true,
    })
    return estado === 200 ? datos.direccion : 'Punto elegido en el mapa'
  },

  async cotizar(s): Promise<ResultadoCotizar> {
    const { estado, datos } = await llamar<CotizacionApi & { motivo?: string; punto?: string }>(
      slugActual(),
      '/cotizar',
      {
        metodo: 'POST',
        conToken: true,
        cuerpo: {
          tipo: s.tipo,
          recogida: { lat: s.recogida.lat, lng: s.recogida.lng },
          entrega: { lat: s.entrega.lat, lng: s.entrega.lng },
          modalidad: s.modalidad,
          importe_producto: s.importeProducto || null,
          lo_antes_posible: s.loAntesPosible,
          ...(s.loAntesPosible ? {} : { fecha: s.fecha, hora: s.hora }),
        },
      },
    )
    if (estado === 200) {
      return {
        ok: true,
        cotizacion: {
          km: datos.km,
          // Sin minutos de Google, un estimado de ciudad: 22 km/h y 4 minutos para salir.
          minutos: datos.minutos ?? Math.max(6, Math.round((datos.km / 22) * 60) + 4),
          importeEnvio: datos.importe_envio,
          importeProducto: datos.importe_producto,
          total: datos.total,
          sello: datos.sello,
          venceEn: datos.vence_en,
        },
      }
    }
    if (datos?.motivo === 'FUERA_DE_ZONA') {
      return {
        ok: false,
        motivo: 'FUERA_DE_ZONA',
        punto: datos.punto === 'recogida' ? 'recogida' : 'entrega',
      }
    }
    if (datos?.motivo === 'SIN_TARIFAS') return { ok: false, motivo: 'SIN_TARIFAS' }
    return { ok: false, motivo: 'SIN_RUTA' }
  },

  async direcciones() {
    const { estado, datos } = await llamar<{ data: DireccionApi[] }>(slugActual(), '/direcciones', {
      conToken: true,
    })
    if (estado !== 200) throw new Error('No se pudieron cargar tus direcciones')
    return datos.data.map(aDireccion)
  },

  async guardarDireccion(d) {
    const ruta = d.id ? `/direcciones/${d.id}` : '/direcciones'
    const { estado, datos } = await llamar<DireccionApi>(slugActual(), ruta, {
      metodo: d.id ? 'PUT' : 'POST',
      conToken: true,
      cuerpo: {
        alias: d.alias,
        direccion: d.direccion,
        lat: d.lat,
        lng: d.lng,
        referencias: d.referencias || null,
      },
    })
    if (estado !== 200 && estado !== 201) throw new Error('No se pudo guardar la dirección')
    return aDireccion(datos)
  },

  async borrarDireccion(id) {
    const { estado } = await llamar(slugActual(), `/direcciones/${id}`, {
      metodo: 'DELETE',
      conToken: true,
    })
    if (estado !== 204) throw new Error('No se pudo borrar la dirección')
  },
}

interface CotizacionApi {
  km: number
  minutos: number | null
  importe_envio: number
  importe_producto: number
  total: number
  sello: string
  vence_en: string
}

interface DireccionApi {
  id: number
  alias: string
  direccion: string
  lat: number
  lng: number
  referencias: string
}

function aDireccion(d: DireccionApi): Direccion {
  return {
    id: d.id,
    alias: d.alias,
    direccion: d.direccion,
    lat: d.lat,
    lng: d.lng,
    referencias: d.referencias,
  }
}
