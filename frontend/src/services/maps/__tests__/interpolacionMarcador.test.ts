import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  DURACION_INICIAL_MS,
  DURACION_MAXIMA_MS,
  DURACION_MINIMA_MS,
  PAUSA_MAXIMA_MS,
  iniciarMovimiento,
  planearMovimiento,
  posicionEnTramo,
} from '../interpolacionMarcador.ts'

const ORIGEN = { lat: 20.5268, lng: -100.838 }
// Unos 45 m al norte, lo que avanza una moto a 40 km/h en 4 segundos.
const CERCA = { lat: 20.5272, lng: -100.838 }
const MAS_CERCA = { lat: 20.5276, lng: -100.838 }
// Más de un kilómetro al norte.
const LEJOS = { lat: 20.54, lng: -100.838 }

describe('planearMovimiento', () => {
  it('no hace nada si el destino no cambió', () => {
    const actual = iniciarMovimiento(ORIGEN)

    assert.equal(planearMovimiento(actual, ORIGEN, { ...ORIGEN }, 1000), null)
  })

  it('anima la primera posición con la duración inicial, sin intervalo que medir', () => {
    const plan = planearMovimiento(iniciarMovimiento(ORIGEN), ORIGEN, CERCA, 1000)

    assert.equal(plan?.tramo?.duracion, DURACION_INICIAL_MS)
    assert.deepEqual(plan?.tramo?.desde, ORIGEN)
    assert.deepEqual(plan?.destino, CERCA)
  })

  it('dura lo que tardó en llegar la posición', () => {
    const actual = { destino: ORIGEN, recibidoEn: 1000, tramo: null }

    assert.equal(planearMovimiento(actual, ORIGEN, CERCA, 3000)?.tramo?.duracion, 2000)
  })

  it('mantiene la duración entre el mínimo y el máximo', () => {
    const actual = { destino: ORIGEN, recibidoEn: 1000, tramo: null }

    assert.equal(
      planearMovimiento(actual, ORIGEN, CERCA, 1100)?.tramo?.duracion,
      DURACION_MINIMA_MS,
    )
    assert.equal(
      planearMovimiento(actual, ORIGEN, CERCA, 21_000)?.tramo?.duracion,
      DURACION_MAXIMA_MS,
    )
  })

  it('sigue desde donde se ve el marcador si la posición llega a media animación', () => {
    const actual = {
      destino: CERCA,
      recibidoEn: 1000,
      tramo: { desde: ORIGEN, inicio: 1000, duracion: 4000 },
    }
    const dibujada = posicionEnTramo(CERCA, actual.tramo, 3000).posicion

    const plan = planearMovimiento(actual, dibujada, MAS_CERCA, 3000)

    assert.deepEqual(plan?.tramo?.desde, dibujada)
    assert.deepEqual(plan?.destino, MAS_CERCA)
  })

  it('salta sin animar si la distancia no es un movimiento creíble', () => {
    const plan = planearMovimiento(iniciarMovimiento(ORIGEN), ORIGEN, LEJOS, 1000)

    assert.equal(plan?.tramo, null)
    assert.deepEqual(plan?.destino, LEJOS)
  })

  it('salta sin animar tras una pausa larga sin posiciones', () => {
    const actual = { destino: ORIGEN, recibidoEn: 1000, tramo: null }

    assert.equal(planearMovimiento(actual, ORIGEN, CERCA, 1000 + PAUSA_MAXIMA_MS + 1)?.tramo, null)
  })

  it('salta sin animar cuando la animación no está permitida', () => {
    const plan = planearMovimiento(iniciarMovimiento(ORIGEN), ORIGEN, CERCA, 1000, false)

    assert.equal(plan?.tramo, null)
    assert.deepEqual(plan?.destino, CERCA)
  })
})

describe('posicionEnTramo', () => {
  const tramo = { desde: ORIGEN, inicio: 1000, duracion: 2000 }

  it('empieza en el origen', () => {
    assert.deepEqual(posicionEnTramo(CERCA, tramo, 1000), { posicion: ORIGEN, terminado: false })
  })

  it('avanza en proporción al tiempo', () => {
    const { posicion, terminado } = posicionEnTramo(CERCA, tramo, 2000)

    assert.equal(terminado, false)
    assert.ok(Math.abs(posicion.lat - (ORIGEN.lat + CERCA.lat) / 2) < 1e-9)
  })

  it('termina exactamente en el destino', () => {
    assert.deepEqual(posicionEnTramo(CERCA, tramo, 3000), { posicion: CERCA, terminado: true })
  })

  it('no se pasa del destino si el navegador pausó la animación', () => {
    assert.deepEqual(posicionEnTramo(CERCA, tramo, 999_999), { posicion: CERCA, terminado: true })
  })

  it('no retrocede si el reloj va antes del inicio', () => {
    assert.deepEqual(posicionEnTramo(CERCA, tramo, 500).posicion, ORIGEN)
  })
})
