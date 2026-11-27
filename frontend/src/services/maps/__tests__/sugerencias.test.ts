import assert from 'node:assert/strict'
import { test } from 'node:test'
import { estadoDeSugerencia } from '../sugerencias.ts'

test('toma el estado del penúltimo tramo de una sugerencia en México', () => {
  assert.equal(estadoDeSugerencia('Lopez, Arboledas del Pedregal, Celaya, Gto., México'), 'Gto.')
  assert.equal(estadoDeSugerencia('México 45, Saláices, Chih., México'), 'Chih.')
  assert.equal(estadoDeSugerencia('Privada López, San Rafael de Yustis, Gto., México'), 'Gto.')
})

test('quita el código postal que a veces viene pegado al estado', () => {
  assert.equal(estadoDeSugerencia('Av. Tecnológico 100, Celaya, Gto. 38010, México'), 'Gto.')
})

test('no adivina cuando el texto no tiene la forma de una dirección mexicana', () => {
  assert.equal(estadoDeSugerencia('Celaya'), null)
  assert.equal(estadoDeSugerencia('Calle 1, Springfield, USA'), null)
})
