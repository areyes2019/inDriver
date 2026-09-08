import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import http from '@/lib/http'

export type Ambiente = 'live' | 'test'

/**
 * Interruptor TEST/LIVE del tenant (spec tenant/025).
 *
 * El estado real vive en el servidor (`configuraciones_tenant`), no aquí: este store es solo el
 * reflejo para pintar la cabecera. El filtrado de envíos por ambiente lo hace el backend con un
 * global scope, así que ninguna vista del Panel tiene que consultarlo para listar correctamente.
 */
export const useAmbienteStore = defineStore('ambiente', () => {
  const ambiente = ref<Ambiente>('live')
  const cargado = ref(false)
  const guardando = ref(false)

  const esTest = computed(() => ambiente.value === 'test')

  async function cargar(slug: string): Promise<void> {
    try {
      const { data } = await http.get(`/t/${slug}/configuracion`)
      ambiente.value = data.ambiente === 'test' ? 'test' : 'live'
    } catch {
      // Un Despachador sin permiso de configuración, o un fallo de red, no pueden dejar el Panel
      // sin cabecera: se asume LIVE, que es la omisión del backend.
      ambiente.value = 'live'
    } finally {
      cargado.value = true
    }
  }

  async function cambiar(slug: string, nuevo: Ambiente): Promise<void> {
    if (guardando.value || nuevo === ambiente.value) {
      return
    }

    guardando.value = true

    try {
      const { data } = await http.put(`/t/${slug}/configuracion/ambiente`, { ambiente: nuevo })
      ambiente.value = data.ambiente

      // Los listados que ya están en pantalla pertenecen al ambiente anterior. Recargar es la
      // forma más honesta de cambiar de mundo: evita dejar mezclados en la misma tabla envíos de
      // los dos ambientes mientras el usuario navega.
      window.location.reload()
    } finally {
      guardando.value = false
    }
  }

  function reset(): void {
    ambiente.value = 'live'
    cargado.value = false
  }

  return { ambiente, cargado, guardando, esTest, cargar, cambiar, reset }
})
