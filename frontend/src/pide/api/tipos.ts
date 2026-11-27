/**
 * Tipos de la app de clientes (spec tenant/035). Son el contrato entre las pantallas y la API: el
 * prototipo (Fase 2) los llena con datos simulados y las fases siguientes con el backend real, sin
 * que las pantallas cambien.
 */

export type EstadoPedido =
  | 'PENDIENTE'
  | 'PUBLICADO'
  | 'TOMADO'
  | 'ARRIBADO'
  | 'EN_CAMINO'
  | 'ARRIBADO_A_ENTREGA'
  | 'ENTREGADO'
  | 'INCIDENCIA'
  | 'EN_REGRESO'
  | 'DEVUELTO'
  | 'CANCELADO'

export type TipoServicio = 'ENVIAR' | 'RECIBIR'

export type ModalidadPago =
  'RECEPTOR_PAGA_ENVIO' | 'REMITENTE_PAGA_ENVIO' | 'RECEPTOR_PAGA_ENVIO_PRODUCTOS'

export type GrupoPedidos = 'activos' | 'completados' | 'cancelados'

export interface Coordenada {
  lat: number
  lng: number
}

export interface Punto extends Coordenada {
  direccion: string
}

export interface Contacto {
  nombre: string
  telefono: string
}

export interface Marca {
  slug: string
  nombre: string
  logoUrl: string | null
  bannerUrl: string | null
  telefonoContacto: string | null
  servicios: { enviar: boolean; recibir: boolean }
  /** `app_clientes` de la flotilla (RN-01). */
  disponible: boolean
  /** Centro de la zona de servicio, para abrir el mapa. */
  centro: Coordenada
  /** Texto corto del horario, para el aviso de "fuera de horario" (RN-29). */
  horario: string
  /** Si en este momento se puede pedir "lo antes posible" (RN-29). */
  abiertoAhora: boolean
}

export interface Cliente {
  id: number
  nombre: string
  /** Contacto para el repartidor; sin verificar (D23). */
  telefono: string
  /** Correo de acceso verificado: la identidad en la app (D23). */
  email: string
}

/** Lo que se captura al registrarse (6.1). */
export interface DatosRegistro {
  nombre: string
  telefono: string
}

export interface Direccion extends Punto {
  id: number
  alias: string
  referencias: string
}

export interface SolicitudPedido {
  tipo: TipoServicio
  recogida: Punto
  referenciasRecogida: string
  contactoRecogida: Contacto
  entrega: Punto
  referenciasEntrega: string
  recibe: Contacto
  modalidad: ModalidadPago
  importeProducto: number
  loAntesPosible: boolean
  /** `YYYY-MM-DD`, solo si programa. */
  fecha: string | null
  /** `HH:mm`, solo si programa. */
  hora: string | null
}

export interface Cotizacion {
  km: number
  minutos: number
  importeEnvio: number
  importeProducto: number
  total: number
  /** Sello cifrado del servidor (RN-23). La app no lo interpreta. */
  sello: string
  /** ISO 8601. */
  venceEn: string
}

export interface Repartidor {
  nombre: string
  telefono: string
  /** Color del globo con iniciales (spec tenant/033). */
  color: string
}

export interface Pedido {
  id: number
  numero: string
  tipo: TipoServicio
  estado: EstadoPedido
  recogida: Punto
  referenciasRecogida: string
  contactoRecogida: Contacto
  entrega: Punto
  referenciasEntrega: string
  recibe: Contacto
  modalidad: ModalidadPago
  importeEnvio: number
  importeProducto: number
  km: number
  /** ISO 8601. */
  creadoEn: string
  /** ISO 8601 de `hora_desde`, o `null` si es "lo antes posible". */
  programadoPara: string | null
  repartidor: Repartidor | null
  /** Última posición conocida del repartidor, solo con el pedido activo (RN-42). */
  posicion: Coordenada | null
  /** Lo canceló el modo automático por falta de repartidor (RN-38). */
  sinRepartidor: boolean
}

export interface Notificacion {
  id: number
  idPedido: number
  titulo: string
  mensaje: string
  creadaEn: string
  leida: boolean
}

export type MotivoCodigo =
  'NO_ENCONTRADO' | 'CORREO_INVALIDO' | 'LIMITE' | 'NO_DISPONIBLE' | 'NO_SE_PUDO_ENVIAR'

export type ResultadoCodigo = { ok: true } | { ok: false; motivo: MotivoCodigo }

export type ResultadoVerificar =
  | { ok: true; token: string; cliente: Cliente; nuevo: boolean }
  | {
      ok: false
      motivo: 'CODIGO_INCORRECTO' | 'CODIGO_VENCIDO' | 'BLOQUEADO' | 'CLIENTE_INACTIVO'
    }

export type ResultadoCotizar =
  | { ok: true; cotizacion: Cotizacion }
  | { ok: false; motivo: 'FUERA_DE_ZONA'; punto: 'recogida' | 'entrega' }
  | { ok: false; motivo: 'SIN_RUTA' | 'SIN_TARIFAS' }

export type ResultadoCrear =
  | { ok: true; pedido: Pedido }
  | { ok: false; motivo: 'PRECIO_CAMBIO'; cotizacion: Cotizacion }
  | { ok: false; motivo: 'NO_DISPONIBLE' | 'LIMITE' }

/** Lo que llega por el canal del cliente (RN-41, RN-42). */
export interface ActualizacionPedido {
  idPedido: number
  estado?: EstadoPedido
  repartidor?: Repartidor | null
  posicion?: Coordenada
  sinRepartidor?: boolean
}
