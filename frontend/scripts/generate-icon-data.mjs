// Extrae solo los íconos de Iconify que la app realmente usa desde las colecciones completas
// (@iconify-json/flat-color-icons, @iconify-json/fluent-color) y los guarda en
// src/assets/icon-data.json. Ese archivo chico es el que se importa en tiempo de ejecución —
// las colecciones completas (miles de íconos) nunca llegan al bundle de producción.
//
// Para agregar un ícono nuevo: agrega su nombre a FLAT_COLOR_ICON_NAMES o
// FLUENT_COLOR_ICON_NAMES abajo y vuelve a correr `npm run icons:build`.
import { writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { getIconData } from '@iconify/utils'
import flatColorIcons from '@iconify-json/flat-color-icons/icons.json' with { type: 'json' }
import fluentColor from '@iconify-json/fluent-color/icons.json' with { type: 'json' }

const FLAT_COLOR_ICON_NAMES = [
  'menu',
  'cancel',
  'settings',
  'search',
  'flash-on',
  'package',
  'automotive',
  'shipped',
  'statistics',
  'positive-dynamic',
  'organization',
  'globe',
  'paid',
  'shop',
]

// fluent-color solo cubre los íconos que no existen en flat-color-icons (ej. no tiene campana ni
// un pin de ubicación).
const FLUENT_COLOR_ICON_NAMES = ['alert-badge-24', 'pin-24']

const output = {}

for (const name of FLAT_COLOR_ICON_NAMES) {
  const data = getIconData(flatColorIcons, name)
  if (!data) throw new Error(`Ícono no encontrado en flat-color-icons: ${name}`)
  output[`flat-color-icons:${name}`] = data
}

for (const name of FLUENT_COLOR_ICON_NAMES) {
  const data = getIconData(fluentColor, name)
  if (!data) throw new Error(`Ícono no encontrado en fluent-color: ${name}`)
  output[`fluent-color:${name}`] = data
}

const outPath = fileURLToPath(new URL('../src/assets/icon-data.json', import.meta.url))
writeFileSync(outPath, JSON.stringify(output, null, 2) + '\n')
console.log(`${Object.keys(output).length} íconos escritos en ${outPath}`)
