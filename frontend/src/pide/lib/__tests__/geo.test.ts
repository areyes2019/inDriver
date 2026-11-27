import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
  distanciaM,
  largoDeTrazo,
  minutosRestantes,
  puntoEnTrazo,
  restanteEnTrazo,
} from '../geo.ts'

// Tres puntos sobre una misma calle de Celaya, ~1.1 km cada tramo.
const A = { lat: 20.52, lng: -100.82 }
const B = { lat: 20.53, lng: -100.82 }
const C = { lat: 20.54, lng: -100.82 }
const trazo = [A, B, C]

test('distancia entre dos puntos', () => {
  assert.ok(Math.abs(distanciaM(A, B) - 1112) < 5)
})

test('largo del trazo es la suma de sus tramos', () => {
  assert.ok(Math.abs(largoDeTrazo(trazo) - 2 * distanciaM(A, B)) < 1)
})

test('lo que falta se mide desde el punto más cercano', () => {
  assert.ok(Math.abs(restanteEnTrazo(trazo, B) - distanciaM(B, C)) < 1)
  assert.ok(restanteEnTrazo(trazo, C) < 1)
})

test('los minutos son proporcionales a lo que falta, nunca menos de 1', () => {
  assert.equal(minutosRestantes(trazo, A, 10), 10)
  assert.equal(minutosRestantes(trazo, B, 10), 5)
  assert.equal(minutosRestantes(trazo, { lat: 20.5399, lng: -100.82 }, 10), 1)
})

test('punto a la mitad del trazo', () => {
  const medio = puntoEnTrazo(trazo, 0.5)
  assert.ok(Math.abs(medio.lat - B.lat) < 0.0001)
  assert.deepEqual(puntoEnTrazo(trazo, 0), A)
  assert.deepEqual(puntoEnTrazo(trazo, 1), C)
})
