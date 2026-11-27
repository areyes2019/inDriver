import assert from 'node:assert/strict'
import { test } from 'node:test'
import { fechaCorta, minutosDeTexto } from '../formato.ts'

test('entiende la duración de Google en español', () => {
  assert.equal(minutosDeTexto('8 min'), 8)
  assert.equal(minutosDeTexto('1 h 5 min'), 65)
  assert.equal(minutosDeTexto('1 hora 5 minutos'), 65)
  assert.equal(minutosDeTexto('2 h'), 120)
  assert.equal(minutosDeTexto('pronto'), null)
})

test('fecha corta relativa a hoy', () => {
  const ahora = new Date(2026, 10, 26, 18, 0)
  assert.match(fechaCorta(new Date(2026, 10, 26, 17, 24).toISOString(), ahora), /^Hoy, /)
  assert.match(fechaCorta(new Date(2026, 10, 25, 9, 10).toISOString(), ahora), /^Ayer, /)
  assert.match(fechaCorta(new Date(2026, 10, 27, 9, 10).toISOString(), ahora), /^Mañana, /)
})
