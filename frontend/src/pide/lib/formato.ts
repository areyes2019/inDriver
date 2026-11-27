import type { ModalidadPago } from '../api/tipos.ts'

const PESOS = new Intl.NumberFormat('es-MX', {
  style: 'currency',
  currency: 'MXN',
  maximumFractionDigits: 2,
  minimumFractionDigits: 0,
})

export function pesos(importe: number): string {
  return PESOS.format(importe)
}

export function km(valor: number): string {
  return `${valor.toLocaleString('es-MX', { maximumFractionDigits: 1 })} km`
}

/** "Hoy, 17:24", "Ayer, 09:10" o "12 nov, 13:00". */
export function fechaCorta(iso: string, ahora: Date = new Date()): string {
  const fecha = new Date(iso)
  const hora = fecha.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' })
  const dia = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const diferencia = Math.round((dia(ahora) - dia(fecha)) / 86_400_000)

  if (diferencia === 0) return `Hoy, ${hora}`
  if (diferencia === 1) return `Ayer, ${hora}`
  if (diferencia === -1) return `Mañana, ${hora}`
  const corta = fecha.toLocaleDateString('es-MX', { day: 'numeric', month: 'short' })
  return `${corta.replace('.', '')}, ${hora}`
}

/**
 * Minutos del texto de duración de Google ("8 min", "1 h 5 min", "1 hora 5 minutos"). Si no se
 * entiende, `null`.
 */
export function minutosDeTexto(texto: string): number | null {
  const horas = /(\d+)\s*h/i.exec(texto)
  const minutos = /(\d+)\s*min/i.exec(texto)
  if (!horas && !minutos) return null
  return Number(horas?.[1] ?? 0) * 60 + Number(minutos?.[1] ?? 0)
}

export const MODALIDADES: ReadonlyArray<{
  valor: ModalidadPago
  titulo: string
  detalle: string
}> = [
  {
    valor: 'REMITENTE_PAGA_ENVIO',
    titulo: 'Quien envía paga',
    detalle: 'Se paga el envío al recoger',
  },
  {
    valor: 'RECEPTOR_PAGA_ENVIO',
    titulo: 'Quien recibe paga',
    detalle: 'Se paga el envío al entregar',
  },
  {
    valor: 'RECEPTOR_PAGA_ENVIO_PRODUCTOS',
    titulo: 'Quien recibe paga envío + producto',
    detalle: 'El repartidor cobra los dos al entregar',
  },
]

export function tituloModalidad(modalidad: ModalidadPago): string {
  return MODALIDADES.find((m) => m.valor === modalidad)?.titulo ?? ''
}
