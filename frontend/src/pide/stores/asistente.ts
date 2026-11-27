import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import type {
  Contacto,
  Cotizacion,
  ModalidadPago,
  Pedido,
  Punto,
  SolicitudPedido,
  TipoServicio,
} from '../api/tipos'
import { esTelefonoValido } from '../lib/telefono'
import { useMarcaStore } from './marca'
import { useSesionStore } from './sesion'

export type Paso = 1 | 2 | 3 | 4

interface Borrador {
  tipo: TipoServicio
  paso: Paso
  recogida: Punto | null
  referenciasRecogida: string
  entregaOtraPersona: boolean
  contactoRecogida: Contacto
  entrega: Punto | null
  referenciasEntrega: string
  recibe: Contacto
  modalidad: ModalidadPago | null
  importeProducto: string
  loAntesPosible: boolean
  fecha: string
  hora: string
}

function nuevaLlave(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : `${Date.now()}-${Math.random().toString(16).slice(2)}`
}

/** El asistente de 4 pasos (spec tenant/035, 6.3). El borrador se guarda por flotilla (RN-09). */
export const useAsistenteStore = defineStore('pide-asistente', () => {
  const b = ref<Borrador>(vacio('ENVIAR'))
  const cotizacion = ref<Cotizacion | null>(null)
  /** RN-31: una llave por intento; cambia si cambia cualquier dato. */
  const llave = ref(nuevaLlave())

  function vacio(tipo: TipoServicio): Borrador {
    return {
      tipo,
      paso: 1,
      recogida: null,
      referenciasRecogida: '',
      entregaOtraPersona: false,
      contactoRecogida: { nombre: '', telefono: '' },
      entrega: null,
      referenciasEntrega: '',
      recibe: { nombre: '', telefono: '' },
      modalidad: null,
      importeProducto: '',
      loAntesPosible: true,
      fecha: '',
      hora: '',
    }
  }

  /** Empieza de cero con los rellenos de cada servicio (6.3). */
  function iniciar(tipo: TipoServicio, ubicacion: Punto | null): void {
    const sesion = useSesionStore()
    b.value = vacio(tipo)
    if (tipo === 'ENVIAR') {
      b.value.recogida = ubicacion
    } else {
      b.value.entrega = ubicacion
      b.value.recibe = {
        nombre: sesion.cliente?.nombre ?? '',
        telefono: sesion.cliente?.telefono ?? '',
      }
    }
    cotizacion.value = null
    llave.value = nuevaLlave()
  }

  /** "Repetir" (RN-55): mismos datos, cotización nueva. */
  function repetir(pedido: Pedido): void {
    const sesion = useSesionStore()
    const propio = pedido.contactoRecogida.telefono === sesion.cliente?.telefono
    b.value = {
      ...vacio(pedido.tipo),
      recogida: { ...pedido.recogida },
      referenciasRecogida: pedido.referenciasRecogida,
      entregaOtraPersona: !propio,
      contactoRecogida: propio ? { nombre: '', telefono: '' } : { ...pedido.contactoRecogida },
      entrega: { ...pedido.entrega },
      referenciasEntrega: pedido.referenciasEntrega,
      recibe: { ...pedido.recibe },
      modalidad: pedido.modalidad,
      importeProducto: pedido.importeProducto ? String(pedido.importeProducto) : '',
    }
    cotizacion.value = null
    llave.value = nuevaLlave()
  }

  function restaurarBorrador(tipo: TipoServicio): boolean {
    const guardado = useMarcaStore().almacen.leerJson<Borrador>('borrador')
    if (!guardado || guardado.tipo !== tipo) return false
    b.value = { ...guardado, paso: Math.min(guardado.paso, 3) as Paso }
    cotizacion.value = null
    return true
  }

  function descartarBorrador(): void {
    useMarcaStore().almacen.borrar('borrador')
  }

  // Cualquier cambio en los datos invalida la cotización y la llave (RN-24, RN-31).
  watch(
    () => {
      const { paso: _paso, ...datos } = b.value
      return JSON.stringify(datos)
    },
    () => {
      cotizacion.value = null
      llave.value = nuevaLlave()
      useMarcaStore().almacen.guardarJson('borrador', b.value)
    },
  )
  watch(
    () => b.value.paso,
    () => useMarcaStore().almacen.guardarJson('borrador', b.value),
  )

  const contactoValido = (c: Contacto) =>
    c.nombre.trim().length >= 2 && esTelefonoValido(c.telefono)

  const pasoValido = computed<Record<Paso, boolean>>(() => {
    const d = b.value
    const producto = Number(d.importeProducto)
    // RN-28: un programado va al menos 30 minutos en el futuro.
    const horaValida =
      d.fecha !== '' &&
      d.hora !== '' &&
      new Date(`${d.fecha}T${d.hora}:00`).getTime() >= Date.now() + 30 * 60_000
    return {
      1: d.recogida !== null && (!d.entregaOtraPersona || contactoValido(d.contactoRecogida)),
      2: d.entrega !== null && contactoValido(d.recibe),
      3:
        d.modalidad !== null &&
        (d.modalidad !== 'RECEPTOR_PAGA_ENVIO_PRODUCTOS' || producto > 0) &&
        (d.loAntesPosible || horaValida),
      4: cotizacion.value !== null,
    }
  })

  const solicitud = computed<SolicitudPedido | null>(() => {
    const d = b.value
    const sesion = useSesionStore()
    if (!d.recogida || !d.entrega || !d.modalidad) return null
    return {
      tipo: d.tipo,
      recogida: d.recogida,
      referenciasRecogida: d.referenciasRecogida.trim(),
      contactoRecogida: d.entregaOtraPersona
        ? d.contactoRecogida
        : { nombre: sesion.cliente?.nombre ?? '', telefono: sesion.cliente?.telefono ?? '' },
      entrega: d.entrega,
      referenciasEntrega: d.referenciasEntrega.trim(),
      recibe: d.recibe,
      modalidad: d.modalidad,
      importeProducto:
        d.modalidad === 'RECEPTOR_PAGA_ENVIO_PRODUCTOS' ? Number(d.importeProducto) || 0 : 0,
      loAntesPosible: d.loAntesPosible,
      fecha: d.loAntesPosible ? null : d.fecha,
      hora: d.loAntesPosible ? null : d.hora,
    }
  })

  return {
    b,
    cotizacion,
    llave,
    pasoValido,
    solicitud,
    iniciar,
    repetir,
    restaurarBorrador,
    descartarBorrador,
  }
})
