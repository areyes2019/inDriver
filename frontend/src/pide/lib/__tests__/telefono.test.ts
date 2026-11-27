import assert from 'node:assert/strict'
import { test } from 'node:test'
import { esTelefonoValido, formatoTelefono, soloDiezDigitos } from '../telefono.ts'

test('deja solo 10 dígitos y quita prefijos viejos', () => {
  assert.equal(soloDiezDigitos('461 123 4567'), '4611234567')
  assert.equal(soloDiezDigitos('+52 461 123 4567'), '4611234567')
  assert.equal(soloDiezDigitos('+52 1 461 123 4567'), '4611234567')
  assert.equal(soloDiezDigitos('044 461 123 4567'), '4611234567')
})

test('valida 10 dígitos', () => {
  assert.equal(esTelefonoValido('4611234567'), true)
  assert.equal(esTelefonoValido('461123456'), false)
})

test('formatea en grupos de 3, 3 y 4', () => {
  assert.equal(formatoTelefono('4611234567'), '461 123 4567')
  assert.equal(formatoTelefono('4611'), '461 1')
})
