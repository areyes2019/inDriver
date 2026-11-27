import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import { api } from '../api'
import type { Marca } from '../api/tipos'
import { crearAlmacen, type Almacen } from '../lib/almacen'

/** La flotilla de esta app: su marca y su almacenamiento propio (RN-05, RN-09). */
export const useMarcaStore = defineStore('pide-marca', () => {
  const slug = ref('')
  const marca = ref<Marca | null>(null)
  const almacen = shallowRef<Almacen>(crearAlmacen('_'))
  const cargando = ref(false)

  const nombre = computed(() => marca.value?.nombre ?? '')

  async function cargar(nuevoSlug: string, forzar = false): Promise<Marca> {
    if (!forzar && marca.value && slug.value === nuevoSlug) return marca.value
    cargando.value = true
    try {
      slug.value = nuevoSlug
      almacen.value = crearAlmacen(nuevoSlug)
      marca.value = await api.marca(nuevoSlug)
      document.title = `${marca.value.nombre} · Pide tu mandado`
      return marca.value
    } finally {
      cargando.value = false
    }
  }

  return { slug, marca, almacen, cargando, nombre, cargar }
})
