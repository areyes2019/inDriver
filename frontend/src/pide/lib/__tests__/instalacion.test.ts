import assert from 'node:assert/strict'
import { test } from 'node:test'
import { destinoPorInstalacion, plataformaDe, slugDeRuta } from '../instalacion.ts'

test('lee la flotilla de la ruta de la app', () => {
  assert.equal(slugDeRuta('/pide/moto-celaya/'), 'moto-celaya')
  assert.equal(slugDeRuta('/pide/moto-celaya/registro'), 'moto-celaya')
  assert.equal(slugDeRuta('/pide/moto-celaya'), 'moto-celaya')
  assert.equal(slugDeRuta('/pide/'), null)
  assert.equal(slugDeRuta('/moto-celaya/panel'), null)
})

test('sin la app instalada, toda pantalla lleva a la bienvenida (D7: instalación obligatoria)', () => {
  for (const pantalla of ['registro', 'entrar', 'codigo', 'inicio', 'pedir', 'pedidos', 'cuenta']) {
    assert.equal(destinoPorInstalacion(pantalla, false), 'bienvenida', pantalla)
  }
})

test('la bienvenida y "Muy pronto" se ven sin instalar', () => {
  assert.equal(destinoPorInstalacion('bienvenida', false), null)
  assert.equal(destinoPorInstalacion('no-disponible', false), null)
})

test('ya instalada, la bienvenida no vuelve y lo demás abre normal', () => {
  assert.equal(destinoPorInstalacion('bienvenida', true), 'registro')
  assert.equal(destinoPorInstalacion('registro', true), null)
  assert.equal(destinoPorInstalacion('inicio', true), null)
})

test('reconoce iPhone, iPad y Android', () => {
  assert.equal(plataformaDe('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'), 'iphone')
  assert.equal(plataformaDe('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 5), 'iphone')
  assert.equal(plataformaDe('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 0), 'otra')
  assert.equal(plataformaDe('Mozilla/5.0 (Linux; Android 14; SM-A146M)'), 'android')
})
