import { addIcon } from '@iconify/vue'
import type { IconifyIcon } from '@iconify/types'
import iconData from '@/assets/icon-data.json'

// Generado por scripts/generate-icon-data.mjs a partir de las colecciones completas de Iconify
// (flat-color-icons, fluent-color) — solo contiene los íconos que la app usa, para no cargar
// datos de sobra. Para agregar un ícono nuevo, edita ese script y vuelve a correrlo.
for (const [name, data] of Object.entries(iconData)) {
  addIcon(name, data as IconifyIcon)
}
