import { computed, onUnmounted, shallowRef } from 'vue'

/** RN-25: cuánto se aguanta como mucho un orden congelado aunque el puntero siga encima. */
const MAXIMO_CONGELADO_MS = 3_000

type Clave = number | string

/**
 * Lo que se guarda al congelar: dónde estaba cada elemento y —cuando la lista tiene secciones—
 * en cuál estaba (RN-34).
 */
interface Instantanea<S> {
  posicion: Map<Clave, number>
  seccion: Map<Clave, S>
}

/**
 * Congela el sitio de los elementos de una lista mientras el puntero está encima (spec tenant/027,
 * RN-25 y RN-34).
 *
 * En un panel que se actualiza solo, una fila puede reordenarse justo cuando alguien va a hacerle
 * clic. Los cambios de **contenido** se ven siempre —el estado, el saldo y el badge se actualizan
 * al instante—; lo único que espera es el reacomodo, y como mucho tres segundos: pasado ese punto
 * es peor mentir sobre el orden que mover una fila.
 *
 * Desde la spec tenant/027 v1.1, "sitio" dejó de ser solo la posición: con `seccionDe` la
 * instantánea guarda también la sección de cada elemento, para que un viaje que cambia de estado no
 * salte de "Viajes pendientes" a "Viajes en curso" por debajo del cursor (RN-34). Es el mismo trato
 * que ya se hacía con el orden, aplicado a la otra cosa que mueve una fila de lugar.
 *
 * Los elementos nuevos no se esconden: aparecen al final mientras dura el congelamiento y caen en
 * su sitio al soltarlo.
 */
export function useOrdenEstable<T, S = never>(
  // Un getter y no un `Ref`: las listas vienen de un store de Pinia, donde los `computed` llegan ya
  // desenvueltos y pasarlos por referencia perdería la reactividad.
  obtenerLista: () => T[],
  claveDe: (elemento: T) => Clave,
  // Opcional: sin él la lista no tiene secciones y solo se congela el orden, como antes de la v1.1.
  seccionDe?: (elemento: T) => S,
) {
  // `shallowRef` y no `ref`: la instantánea se reemplaza entera o no se toca, y envolver dos `Map`
  // en un proxy profundo solo cuesta trabajo para nada.
  const congelado = shallowRef<Instantanea<S> | null>(null)
  let temporizador: ReturnType<typeof setTimeout> | null = null

  const elementos = computed<T[]>(() => {
    const actual = obtenerLista()
    const instantanea = congelado.value

    if (instantanea === null) return actual

    return [...actual].sort((a, b) => {
      // Lo que no estaba cuando se congeló va al final, en el orden que trae la lista.
      const posA = instantanea.posicion.get(claveDe(a)) ?? Number.POSITIVE_INFINITY
      const posB = instantanea.posicion.get(claveDe(b)) ?? Number.POSITIVE_INFINITY
      return posA - posB
    })
  })

  /**
   * La sección en la que toca pintar un elemento: la congelada si la hay, la viva si no (RN-34).
   *
   * Un elemento que llegó después de congelar no tiene sección guardada y cae en la que le
   * corresponde de verdad: retenerlo tendría que inventarle un pasado que no tuvo.
   */
  function seccionEstable(elemento: T): S {
    const congelada = congelado.value?.seccion.get(claveDe(elemento))
    return congelada ?? (seccionDe as (elemento: T) => S)(elemento)
  }

  function descongelar() {
    if (temporizador !== null) {
      clearTimeout(temporizador)
      temporizador = null
    }
    congelado.value = null
  }

  function congelar() {
    if (congelado.value !== null) return

    const posicion = new Map<Clave, number>()
    const seccion = new Map<Clave, S>()

    obtenerLista().forEach((elemento, indice) => {
      const clave = claveDe(elemento)
      posicion.set(clave, indice)
      if (seccionDe) seccion.set(clave, seccionDe(elemento))
    })

    congelado.value = { posicion, seccion }
    temporizador = setTimeout(descongelar, MAXIMO_CONGELADO_MS)
  }

  onUnmounted(descongelar)

  return { elementos, seccionEstable, congelar, descongelar }
}
