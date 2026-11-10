import { ref } from 'vue'
import { defineStore } from 'pinia'
import http from '@/lib/http'
import realtimeService from '@/services/realtime'

/**
 * Cuántas conversaciones de WhatsApp esperan a una persona (spec tenant/031, RN-55 y RN-60).
 *
 * Lo leen el menú lateral y la pestaña de WhatsApp del Panel (spec tenant/032, paso 3): un solo
 * número, una sola petición y un solo listener en el canal del Panel.
 *
 * `iniciar`/`detener` llevan la cuenta de quién lo usa. Al cambiar de pantalla, el `TenantLayout`
 * nuevo se monta antes de que se desmonte el anterior; sin la cuenta, el viejo quitaría el listener
 * que el nuevo acaba de necesitar.
 */
export const useBandejaWhatsappStore = defineStore('bandejaWhatsapp', () => {
  const esperando = ref(0)

  let usuarios = 0
  let slugSuscrito: string | null = null

  function onConversacionActualizada(datos: { esperando?: number }) {
    if (typeof datos.esperando === 'number') esperando.value = datos.esperando
  }

  async function cargar(slug: string): Promise<void> {
    try {
      const { data } = await http.get(`/t/${slug}/whatsapp/conversaciones/esperando`)
      esperando.value = data.esperando ?? 0
    } catch {
      // El contador es una ayuda; si falla, el menú y la pestaña siguen funcionando.
    }
  }

  function suscribir(slug: string) {
    realtimeService.subscribePanel(slug)?.bind('whatsapp.conversacion', onConversacionActualizada)
    slugSuscrito = slug
  }

  function desuscribir() {
    if (slugSuscrito === null) return
    realtimeService
      .subscribePanel(slugSuscrito)
      ?.unbind('whatsapp.conversacion', onConversacionActualizada)
    slugSuscrito = null
  }

  // Se vuelve a atar siempre, quitando antes el anterior para no duplicarlo: cerrar sesión
  // (`realtimeService.disconnect()`) borra los listeners del canal sin avisar a este store.
  function iniciar(slug: string) {
    usuarios++
    cargar(slug)
    desuscribir()
    suscribir(slug)
  }

  function detener() {
    usuarios = Math.max(0, usuarios - 1)
    if (usuarios === 0) desuscribir()
  }

  return { esperando, iniciar, detener }
})
