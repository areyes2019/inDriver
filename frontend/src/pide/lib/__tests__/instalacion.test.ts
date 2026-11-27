import assert from 'node:assert/strict'
import { test } from 'node:test'
import { debeMostrarBienvenida, plataformaDe, slugDeRuta } from '../instalacion.ts'

test('lee la flotilla de la ruta de la app', () => {
  assert.equal(slugDeRuta('/pide/moto-celaya/'), 'moto-celaya')
  assert.equal(slugDeRuta('/pide/moto-celaya/registro'), 'moto-celaya')
  assert.equal(slugDeRuta('/pide/moto-celaya'), 'moto-celaya')
  assert.equal(slugDeRuta('/pide/'), null)
  assert.equal(slugDeRuta('/moto-celaya/panel'), null)
})

test('la bienvenida con Instalar solo aparece antes de instalar y sin sesión', () => {
  const base = { conSesion: false, instalada: false, eligioNavegador: false }
  assert.equal(debeMostrarBienvenida(base), true)
  assert.equal(debeMostrarBienvenida({ ...base, instalada: true }), false)
  assert.equal(debeMostrarBienvenida({ ...base, conSesion: true }), false)
  assert.equal(debeMostrarBienvenida({ ...base, eligioNavegador: true }), false)
})

test('reconoce iPhone, iPad y Android', () => {
  assert.equal(plataformaDe('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'), 'iphone')
  assert.equal(plataformaDe('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 5), 'iphone')
  assert.equal(plataformaDe('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 0), 'otra')
  assert.equal(plataformaDe('Mozilla/5.0 (Linux; Android 14; SM-A146M)'), 'android')
})
