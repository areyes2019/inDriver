import type {
  ActualizacionPedido,
  Cliente,
  Coordenada,
  DatosRegistro,
  Direccion,
  GrupoPedidos,
  Marca,
  Notificacion,
  Pedido,
  ResultadoCodigo,
  ResultadoCotizar,
  ResultadoCrear,
  ResultadoVerificar,
  SolicitudPedido,
  Cotizacion,
} from './tipos'

/**
 * Lo que las pantallas le piden al servidor (spec tenant/035, sección 8). En la Fase 2 lo cumple
 * `mock.ts`; desde la Fase 3 una implementación sobre `/api/t/{slug}/cliente/*` lo reemplaza sin
 * tocar las pantallas.
 */
export interface ApiCliente {
  marca(slug: string): Promise<Marca>

  /** Código por correo (D6). En "registro" llegan nombre y teléfono; en "entrar", `null`. */
  pedirCodigo(slug: string, email: string, registro: DatosRegistro | null): Promise<ResultadoCodigo>
  verificar(
    slug: string,
    email: string,
    codigo: string,
    registro: DatosRegistro | null,
  ): Promise<ResultadoVerificar>
  salir(): Promise<void>

  perfil(): Promise<Cliente>
  /** RN-57: nombre y celular. El correo de acceso no se cambia desde la app. */
  actualizarPerfil(datos: { nombre: string; telefono: string }): Promise<Cliente>

  direcciones(): Promise<Direccion[]>
  guardarDireccion(direccion: Omit<Direccion, 'id'> & { id?: number }): Promise<Direccion>
  borrarDireccion(id: number): Promise<void>

  /** Revisa que un punto caiga en la zona de servicio (RN-20). */
  validarPunto(punto: Coordenada): Promise<boolean>
  /** Dirección legible de un punto elegido en el mapa. */
  direccionDe(punto: Coordenada): Promise<string>
  cotizar(solicitud: SolicitudPedido): Promise<ResultadoCotizar>
  crearPedido(
    solicitud: SolicitudPedido,
    cotizacion: Cotizacion,
    llave: string,
  ): Promise<ResultadoCrear>

  pedidos(grupo: GrupoPedidos): Promise<Pedido[]>
  pedido(id: number): Promise<Pedido | null>
  cancelar(id: number): Promise<Pedido>
  /** Canal en tiempo real del cliente (RN-41). Devuelve la función para dejar de escuchar. */
  escucharPedido(id: number, alRecibir: (cambio: ActualizacionPedido) => void): () => void
  /** Estado de la conexión en tiempo real (RN-47). Reactivo: se puede leer dentro de `computed`. */
  estadoConexion(): 'conectado' | 'reconectando'

  notificaciones(): Promise<Notificacion[]>
  marcarLeida(id: number): Promise<void>

  /**
   * Solo prototipo: el trazo que dibujó Google para un tramo, para que la moto simulada avance por
   * las calles y no en línea recta. La API real no lo implementa.
   */
  pistaDeTrazo?(idPedido: number, tramo: 'recogida' | 'entrega', trazo: Coordenada[]): void
}
