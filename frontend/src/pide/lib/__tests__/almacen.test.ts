import assert from 'node:assert/strict'
import { test } from 'node:test'
import { crearAlmacen, llaveDe } from '../almacen.ts'

function almacenFalso() {
  const datos = new Map<string, string>()
  return {
    datos,
    getItem: (k: string) => datos.get(k) ?? null,
    setItem: (k: string, v: string) => void datos.set(k, v),
    removeItem: (k: string) => void datos.delete(k),
    get length() {
      return datos.size
    },
    key: (i: number) => [...datos.keys()][i] ?? null,
  }
}

test('cada llave lleva el slug de la flotilla (RN-09)', () => {
  assert.equal(llaveDe('motopanda', 'token'), 'pide:motopanda:token')
})

test('dos flotillas no se leen entre sí', () => {
  const fondo = almacenFalso()
  const a = crearAlmacen('flotilla-a', fondo)
  const b = crearAlmacen('flotilla-b', fondo)

  a.guardar('token', 'token-a')
  b.guardar('token', 'token-b')

  assert.equal(a.leer('token'), 'token-a')
  assert.equal(b.leer('token'), 'token-b')
})

test('cerrar sesión borra todo lo de una flotilla y nada de la otra', () => {
  const fondo = almacenFalso()
  const a = crearAlmacen('flotilla-a', fondo)
  const b = crearAlmacen('flotilla-b', fondo)
  a.guardar('token', 'x')
  a.guardarJson('borrador', { paso: 2 })
  b.guardar('token', 'y')
  fondo.setItem('otra-app', 'z')

  a.borrarTodo()

  assert.equal(a.leer('token'), null)
  assert.equal(a.leer('borrador'), null)
  assert.equal(b.leer('token'), 'y')
  assert.equal(fondo.getItem('otra-app'), 'z')
})

test('sin almacenamiento disponible no truena', () => {
  const roto = {
    getItem: () => {
      throw new Error('bloqueado')
    },
    setItem: () => {
      throw new Error('bloqueado')
    },
    removeItem: () => {
      throw new Error('bloqueado')
    },
    length: 0,
    key: () => null,
  }
  const a = crearAlmacen('x', roto)
  a.guardar('token', 'nada')
  assert.equal(a.leer('token'), null)
})
