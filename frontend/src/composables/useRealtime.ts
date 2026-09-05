/**
 * useRealtime — suscribe al Panel (AdminCliente/Despachador) al canal privado del tenant
 * (spec inDriver tenant/018). Esta spec solo deja la conexión lista: no ata ningún listener de
 * evento todavía — eso es alcance de las specs 020 (oferta/aceptación) y 021 (live tracking).
 */
import { onUnmounted } from 'vue'
import realtimeService from '@/services/realtime'

export function useRealtime(slug: string) {
  realtimeService.subscribe(slug)

  onUnmounted(() => {
    realtimeService.unsubscribe()
  })
}
