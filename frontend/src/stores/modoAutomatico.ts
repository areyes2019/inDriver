import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import http from '@/lib/http'

export type EstadoModoAutomatico = 'No' | 'Si' | 'Pausado'

export interface EntradaBitacora {
  id_bitacora: number
  accion: 'REOFERTA' | 'CANCELACION' | 'PAUSA' | 'PERSONA' | 'ATORADO'
  detalle: string
  id_pedido: number | null
  numero_pedido: string | null
  created_at: string | null
}

/**
 * Modo automático de la flotilla (spec tenant/034). El estado real vive en el servidor; este store
 * es su reflejo para el botón del menú lateral y el diálogo con la bitácora.
 */
export const useModoAutomaticoStore = defineStore('modoAutomatico', () => {
  const estado = ref<EstadoModoAutomatico>('No')
  const cargado = ref(false)
  const guardando = ref(false)
  const bitacora = ref<EntradaBitacora[]>([])
  const cargandoBitacora = ref(false)
  const errorBitacora = ref('')

  const prendido = computed(() => estado.value === 'Si')
  const pausado = computed(() => estado.value === 'Pausado')

  async function cargar(slug: string): Promise<void> {
    try {
      const { data } = await http.get(`/t/${slug}/configuracion`)
      estado.value = normalizar(data.modo_automatico)
    } catch {
      estado.value = 'No'
    } finally {
      cargado.value = true
    }
  }

  async function cambiar(slug: string, nuevo: 'Si' | 'No'): Promise<void> {
    if (guardando.value) return

    guardando.value = true
    try {
      const { data } = await http.put(`/t/${slug}/configuracion/modo-automatico`, {
        modo_automatico: nuevo,
      })
      estado.value = normalizar(data.modo_automatico)
    } finally {
      guardando.value = false
    }
  }

  async function cargarBitacora(slug: string): Promise<void> {
    cargandoBitacora.value = true
    errorBitacora.value = ''
    try {
      const { data } = await http.get(`/t/${slug}/automatico/bitacora`)
      bitacora.value = data.data ?? []
    } catch {
      errorBitacora.value = 'No se pudo cargar la bitácora.'
    } finally {
      cargandoBitacora.value = false
    }
  }

  function normalizar(valor: unknown): EstadoModoAutomatico {
    return valor === 'Si' || valor === 'Pausado' ? valor : 'No'
  }

  return {
    estado,
    cargado,
    guardando,
    prendido,
    pausado,
    bitacora,
    cargandoBitacora,
    errorBitacora,
    cargar,
    cambiar,
    cargarBitacora,
  }
})
