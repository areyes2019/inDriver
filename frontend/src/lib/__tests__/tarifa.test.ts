import assert from 'node:assert/strict'
import { test } from 'node:test'
import { redondearTarifa } from '../tarifa.ts'

test('baja si pasa menos de $1.50 del múltiplo inferior y sube desde $1.50 (spec tenant/006)', () => {
  const casos: Array<[number, number]> = [
    [65, 65],
    [65.01, 65],
    [66, 65],
    [66.49, 65],
    [66.5, 70],
    [67, 70],
    [68.8, 70],
    [63.25, 65],
    [31, 30],
    [32, 35],
  ]
  for (const [total, esperado] of casos) {
    assert.equal(redondearTarifa(total), esperado, String(total))
  }
})

test('el corte no se pierde por error de punto flotante', () => {
  // Un 66.50 que el punto flotante entrega apenas por debajo sigue subiendo.
  assert.equal(redondearTarifa(66.49999999), 70)
})

test('nunca baja de $5', () => {
  assert.equal(redondearTarifa(1.2), 5)
  assert.equal(redondearTarifa(0), 5)
})
