/**
 * RealtimeService — cliente de tiempo real del Panel (spec inDriver tenant/018)
 * ------------------------------------------------------------------------------
 * Mismo canal privado por tenant que ya usa panda_express (spec 013), pero autenticado con la
 * sesión (cookie) del Panel en vez de un token Bearer: pusher-js no maneja solo el CSRF/cookies de
 * Sanctum, así que la autorización del canal se delega en `http` (axios ya configurado con
 * `withCredentials`/`withXSRFToken`) vía un `customHandler` en vez del `endpoint` por defecto.
 *
 * Esta spec solo deja la conexión lista — las specs 020/021 son quienes escuchan eventos
 * concretos y reaccionan en pantalla.
 */
import Pusher, { type Channel } from 'pusher-js'
import http from '@/lib/http'

let currentSlug = ''

class RealtimeService {
  private pusher: Pusher | null = null
  private channel: Channel | null = null
  private connected = false

  /**
   * Inicializa la conexión a Reverb (singleton). Si falta la config, falla en silencio — el Panel
   * sigue funcionando sin tiempo real.
   */
  connect(slug: string) {
    currentSlug = slug
    if (this.pusher) return

    const key = import.meta.env.VITE_REVERB_APP_KEY
    const host = import.meta.env.VITE_REVERB_HOST

    if (!key || !host) {
      console.warn('[Realtime] Reverb no configurado — tiempo real desactivado en el Panel.')
      return
    }

    const port =
      Number(import.meta.env.VITE_REVERB_PORT) ||
      (import.meta.env.VITE_REVERB_SCHEME === 'https' ? 443 : 80)
    const forceTLS = import.meta.env.VITE_REVERB_SCHEME === 'https'

    try {
      this.pusher = new Pusher(key, {
        // pusher-js exige esta clave aunque no se use con un servidor propio (Reverb).
        cluster: '',
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS,
        enabledTransports: forceTLS ? ['wss'] : ['ws'],
        channelAuthorization: {
          customHandler: ({ socketId, channelName }, callback) => {
            http
              .post(`/t/${currentSlug}/broadcasting/auth`, {
                socket_id: socketId,
                channel_name: channelName,
              })
              .then((response) => callback(null, response.data))
              .catch((error) => callback(error, null))
          },
        },
      })
    } catch (err) {
      console.error('[Realtime] No se pudo inicializar Reverb en el Panel:', err)
      this.pusher = null
      return
    }

    this.pusher.connection.bind('connected', () => {
      this.connected = true
    })

    this.pusher.connection.bind('disconnected', () => {
      this.connected = false
    })
  }

  get isConnected(): boolean {
    return this.connected && this.pusher !== null
  }

  /**
   * Se suscribe al canal privado del tenant. Si ya había una suscripción a otro tenant (cambio de
   * sesión/slug sin recargar la página), la cierra primero.
   */
  subscribe(slug: string): Channel | null {
    if (this.channel && currentSlug !== slug) {
      this.unsubscribe()
    }

    this.connect(slug)
    if (!this.pusher) return null

    if (!this.channel) {
      this.channel = this.pusher.subscribe(`private-tenant.${slug}.conductores`)
    }

    return this.channel
  }

  unsubscribe() {
    if (this.channel) {
      this.channel.unbind_all()
      this.pusher?.unsubscribe(this.channel.name)
      this.channel = null
    }
  }

  disconnect() {
    this.unsubscribe()
    if (this.pusher) {
      this.pusher.disconnect()
      this.pusher = null
    }
    this.connected = false
  }
}

export default new RealtimeService()
