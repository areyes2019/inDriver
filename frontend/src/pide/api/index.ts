import type { ApiCliente } from './cliente'
import { apiSimulada } from './mock'
import { apiReal } from './real'

/**
 * La API que usan las pantallas.
 *
 * - `VITE_PIDE_API=real`: acceso y perfil contra el backend (Fase 3); lo demás sigue simulado hasta
 *   su fase.
 * - Sin la variable: todo simulado, como el prototipo de la Fase 2.
 */
export const api: ApiCliente = import.meta.env.VITE_PIDE_API === 'real' ? apiReal : apiSimulada

/** El panel "Demo" existe mientras alguna parte siga simulada. */
export const esPrototipo = true
