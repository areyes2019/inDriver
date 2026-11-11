import assert from 'node:assert/strict'
import { test } from 'node:test'
import { LETRA_CLARA, LETRA_OSCURA, colorDeLetra, svgGlobo } from '../globoConductor.ts'

test('letra oscura solo en ámbar y lima con la paleta de la spec tenant/026 (spec tenant/033, §6)', () => {
  const casos: Array<[string, string]> = [
    ['#2563EB', LETRA_CLARA],
    ['#DC2626', LETRA_CLARA],
    ['#059669', LETRA_CLARA],
    ['#D97706', LETRA_OSCURA],
    ['#7C3AED', LETRA_CLARA],
    ['#DB2777', LETRA_CLARA],
    ['#0891B2', LETRA_CLARA],
    ['#65A30D', LETRA_OSCURA],
    ['#EA580C', LETRA_CLARA],
    ['#4F46E5', LETRA_CLARA],
  ]
  for (const [color, esperado] of casos) {
    assert.equal(colorDeLetra(color), esperado, color)
  }
})

test('un color que no se puede leer deja la letra en blanco', () => {
  assert.equal(colorDeLetra('rojo'), LETRA_CLARA)
  assert.equal(colorDeLetra('#FFF'), LETRA_OSCURA)
})

test('las iniciales se escapan dentro del SVG', () => {
  const svg = svgGlobo('#2563EB', '<&')
  assert.ok(svg.includes('&lt;&amp;'))
  assert.ok(!svg.includes('<&'))
})
