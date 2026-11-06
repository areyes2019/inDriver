import assert from 'node:assert/strict'
import { test } from 'node:test'
import { faseDe, sePuedeCancelar, sePuedeCerrarPorIncidencia } from '../estadosPedido.ts'

test('agrupa los estados internos en las fases de la spec tenant/006', () => {
  assert.equal(faseDe('PENDIENTE'), 'Pendiente')
  assert.equal(faseDe('PUBLICADO'), 'Publicado')
  assert.equal(faseDe('TOMADO'), 'En recogida')
  assert.equal(faseDe('ARRIBADO'), 'En recogida')
  assert.equal(faseDe('EN_CAMINO'), 'En entrega')
  assert.equal(faseDe('ARRIBADO_A_ENTREGA'), 'En entrega')
  assert.equal(faseDe('ENTREGADO'), 'Completado')
})

test('solo se cancela en Pendiente, Publicado y En recogida', () => {
  for (const estado of ['PENDIENTE', 'PUBLICADO', 'TOMADO', 'ARRIBADO']) {
    assert.equal(sePuedeCancelar(estado), true, estado)
    assert.equal(sePuedeCerrarPorIncidencia(estado), false, estado)
  }
})

test('En entrega no se cancela: se cierra por incidencia', () => {
  for (const estado of ['EN_CAMINO', 'ARRIBADO_A_ENTREGA']) {
    assert.equal(sePuedeCancelar(estado), false, estado)
    assert.equal(sePuedeCerrarPorIncidencia(estado), true, estado)
  }
})

test('un estado final no admite ninguna de las dos', () => {
  for (const estado of ['ENTREGADO', 'CANCELADO', 'INCIDENCIA']) {
    assert.equal(sePuedeCancelar(estado), false, estado)
    assert.equal(sePuedeCerrarPorIncidencia(estado), false, estado)
  }
})
