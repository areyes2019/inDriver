import { computed, onUnmounted, ref } from 'vue'

/** RN-25: cuánto se aguanta como mucho un orden congelado aunque el puntero siga encima. */
const MAXIMO_CONGELADO_MS = 3_000

/**
 * Congela el orden de una lista mientras el puntero está encima (spec tenant/027, RN-25).
 *
 * En un panel que se actualiza solo, una fila puede reordenarse justo cuando alguien va a hacerle
 * clic. Los cambios de **contenido** se ven siempre —el estado, el saldo y el badge se actualizan
 * al instante—; lo único que espera es el reacomodo, y como mucho tres segundos: pasado ese punto
 * es peor mentir sobre el orden que mover una fila.
 *
 * Los elementos nuevos no se esconden: aparecen al final mientras dura el congelamiento y caen en
 * su sitio al soltarlo.
 */
export function useOrdenEstable<T>(
  // Un getter y no un `Ref`: las listas vienen de un store de Pinia, donde los `computed` llegan ya
  // desenvueltos y pasarlos por referencia perdería la reactividad.
  obtenerLista: () => T[],
  claveDe: (elemento: T) => number | string,
) {
  const ordenCongelado = ref<Array<number | string> | null>(null)
  let temporizador: ReturnType<typeof setTimeout> | null = null

  const elementos = computed<T[]>(() => {
    const actual = obtenerLista()
    const congelado = ordenCongelado.value

    if (congelado === null) return actual

    const posicion = new Map(congelado.map((clave, indice) => [clave, indice]))

    return [...actual].sort((a, b) => {
      // Lo que no estaba cuando se congeló va al final, en el orden que trae la lista.
      const posA = posicion.get(claveDe(a)) ?? Number.POSITIVE_INFINITY
      const posB = posicion.get(claveDe(b)) ?? Number.POSITIVE_INFINITY
      return posA - posB
    })
  })

  function descongelar() {
    if (temporizador !== null) {
      clearTimeout(temporizador)
      temporizador = null
    }
    ordenCongelado.value = null
  }

  function congelar() {
    if (ordenCongelado.value !== null) return

    ordenCongelado.value = obtenerLista().map(claveDe)
    temporizador = setTimeout(descongelar, MAXIMO_CONGELADO_MS)
  }

  onUnmounted(descongelar)

  return { elementos, congelar, descongelar }
}
