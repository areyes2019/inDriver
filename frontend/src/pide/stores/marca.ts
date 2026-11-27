import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import { api } from '../api'
import type { Marca } from '../api/tipos'
import { crearAlmacen, type Almacen } from '../lib/almacen'

/**
 * La app instalada puede quedarse viva días en segundo plano: pasado este tiempo la marca se vuelve
 * a pedir, para que un cambio del Panel (zonas, app apagada) llegue sin cerrarla.
 */
const VIGENCIA_MS = 5 * 60 * 1000

/** La flotilla de esta app: su marca y su almacenamiento propio (RN-05, RN-09). */
export const useMarcaStore = defineStore('pide-marca', () => {
  const slug = ref('')
  const marca = ref<Marca | null>(null)
  const almacen = shallowRef<Almacen>(crearAlmacen('_'))
  const cargando = ref(false)
  let cargadaEn = 0

  const nombre = computed(() => marca.value?.nombre ?? '')

  async function cargar(nuevoSlug: string, forzar = false): Promise<Marca> {
    const vigente = Date.now() - cargadaEn < VIGENCIA_MS
    if (!forzar && vigente && marca.value && slug.value === nuevoSlug) return marca.value
    cargando.value = true
    try {
      slug.value = nuevoSlug
      almacen.value = crearAlmacen(nuevoSlug)
      marca.value = await api.marca(nuevoSlug)
      cargadaEn = Date.now()
      document.title = `${marca.value.nombre} · Pide tu mandado`
      ponerNombreEnIphone(marca.value.nombre)
      return marca.value
    } finally {
      cargando.value = false
    }
  }

  return { slug, marca, almacen, cargando, nombre, cargar }
})

/** iPhone no lee el nombre del manifiesto al "Agregar a inicio": usa esta etiqueta. */
function ponerNombreEnIphone(nombre: string): void {
  let meta = document.querySelector<HTMLMetaElement>('meta[name="apple-mobile-web-app-title"]')
  if (!meta) {
    meta = document.createElement('meta')
    meta.name = 'apple-mobile-web-app-title'
    document.head.appendChild(meta)
  }
  meta.content = nombre
}
