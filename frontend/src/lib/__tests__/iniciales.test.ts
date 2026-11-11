import assert from 'node:assert/strict'
import { test } from 'node:test'
import { iniciales } from '../iniciales.ts'

test('iniciales del conductor (spec tenant/033, sección 4)', () => {
  const casos: Array<[string, string]> = [
    ['Juan Pérez', 'JP'],
    ['Juan Carlos Pérez', 'JP'],
    ['María de la Cruz', 'MC'],
    ['José del Río', 'JR'],
    ['Juan', 'J'],
    ['  ana   torres  ', 'AT'],
    ['Ángel Núñez', 'ÁN'],
    ['ñoño ñúñez', 'ÑÑ'],
    ['', '?'],
    ['   ', '?'],
    ['de la', '?'],
  ]
  for (const [nombre, esperado] of casos) {
    assert.equal(iniciales(nombre), esperado, JSON.stringify(nombre))
  }
})
