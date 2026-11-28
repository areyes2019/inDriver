import type { Directive } from 'vue'

/**
 * `v-aparecer`: el elemento entra con un desvanecido y un leve desplazamiento la primera vez que se
 * ve en pantalla. Con `v-aparecer="150"` espera esos milisegundos (para escalonar tarjetas).
 *
 * Quien tiene activada la reducción de movimiento en su sistema lo ve directo, sin animación.
 */
const reducido =
  typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches

let observador: IntersectionObserver | null = null

function obtenerObservador(): IntersectionObserver {
  observador ??= new IntersectionObserver(
    (entradas) => {
      for (const entrada of entradas) {
        if (!entrada.isIntersecting) continue
        entrada.target.classList.add('aparecido')
        observador?.unobserve(entrada.target)
      }
    },
    { threshold: 0.12, rootMargin: '0px 0px -40px 0px' },
  )
  return observador
}

export const vAparecer: Directive<HTMLElement, number | undefined> = {
  mounted(el, { value }) {
    // Sin animación se marca de una vez como visto: lo que espera esa marca (los mensajes de la
    // maqueta de WhatsApp) no se queda escondido.
    if (reducido || typeof IntersectionObserver === 'undefined') {
      el.classList.add('aparecido')
      return
    }
    el.classList.add('por-aparecer')
    if (value) el.style.transitionDelay = `${value}ms`
    obtenerObservador().observe(el)
  },
  unmounted(el) {
    observador?.unobserve(el)
  },
}
