import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
  clientePuedeCancelar,
  grupoDe,
  mensajeDe,
  muestraRepartidor,
  pasoDeProgreso,
  tramoDe,
} from '../estados.ts'

test('traduce cada estado al mensaje de RN-40', () => {
  assert.equal(mensajeDe('PUBLICADO'), 'Buscando repartidor')
  assert.equal(mensajeDe('TOMADO'), 'Tu repartidor va por el paquete')
  assert.equal(mensajeDe('EN_CAMINO'), 'Tu paquete va en camino')
  assert.equal(mensajeDe('ENTREGADO'), 'Entregado')
  assert.equal(mensajeDe('CANCELADO'), 'Pedido cancelado')
})

test('una cancelación por falta de repartidor lo dice (RN-38)', () => {
  assert.equal(mensajeDe('CANCELADO', true), 'No encontramos repartidor disponible')
})

test('la línea de progreso tiene 5 puntos y deja fuera los estados raros', () => {
  assert.equal(pasoDeProgreso('PENDIENTE'), 0)
  assert.equal(pasoDeProgreso('ARRIBADO'), 1)
  assert.equal(pasoDeProgreso('EN_CAMINO'), 2)
  assert.equal(pasoDeProgreso('ARRIBADO_A_ENTREGA'), 3)
  assert.equal(pasoDeProgreso('ENTREGADO'), 4)
  assert.equal(pasoDeProgreso('INCIDENCIA'), null)
  assert.equal(pasoDeProgreso('CANCELADO'), null)
})

test('el cliente solo cancela mientras se busca repartidor (RN-36)', () => {
  assert.equal(clientePuedeCancelar('PENDIENTE'), true)
  assert.equal(clientePuedeCancelar('PUBLICADO'), true)
  assert.equal(clientePuedeCancelar('TOMADO'), false)
  assert.equal(clientePuedeCancelar('EN_CAMINO'), false)
})

test('la moto solo se ve con el pedido en curso (RN-42)', () => {
  assert.equal(muestraRepartidor('PUBLICADO'), false)
  assert.equal(muestraRepartidor('TOMADO'), true)
  assert.equal(muestraRepartidor('ARRIBADO_A_ENTREGA'), true)
  assert.equal(muestraRepartidor('ENTREGADO'), false)
})

test('punteado hacia la recogida, continuo hacia la entrega', () => {
  assert.equal(tramoDe('TOMADO'), 'recogida')
  assert.equal(tramoDe('ARRIBADO'), 'recogida')
  assert.equal(tramoDe('EN_CAMINO'), 'entrega')
  assert.equal(tramoDe('PUBLICADO'), null)
})

test('agrupa en activos, completados y cancelados', () => {
  assert.equal(grupoDe('EN_REGRESO'), 'activos')
  assert.equal(grupoDe('INCIDENCIA'), 'activos')
  assert.equal(grupoDe('DEVUELTO'), 'completados')
  assert.equal(grupoDe('CANCELADO'), 'cancelados')
})
