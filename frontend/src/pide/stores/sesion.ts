import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '../api'
import type { Cliente } from '../api/tipos'
import { useMarcaStore } from './marca'

/** Sesión del cliente en esta flotilla (RN-02, RN-03). El token vive en `pide:{slug}:token`. */
export const useSesionStore = defineStore('pide-sesion', () => {
  const token = ref<string | null>(null)
  const cliente = ref<Cliente | null>(null)
  const slugDeSesion = ref('')

  const activa = computed(() => token.value !== null)
  const primerNombre = computed(() => cliente.value?.nombre.split(' ')[0] ?? '')

  /** Lee el token guardado de esta flotilla. Si es de otra, empieza sin sesión. */
  async function restaurar(): Promise<void> {
    const marca = useMarcaStore()
    if (slugDeSesion.value === marca.slug) return
    slugDeSesion.value = marca.slug
    token.value = marca.almacen.leer('token')
    cliente.value = null
    if (token.value) {
      try {
        cliente.value = await api.perfil()
      } catch {
        cerrarLocal()
      }
    }
  }

  function entrar(nuevoToken: string, datos: Cliente): void {
    const marca = useMarcaStore()
    token.value = nuevoToken
    cliente.value = datos
    slugDeSesion.value = marca.slug
    marca.almacen.guardar('token', nuevoToken)
  }

  async function salir(): Promise<void> {
    try {
      await api.salir()
    } finally {
      cerrarLocal()
    }
  }

  function cerrarLocal(): void {
    // RN-09: borra todo lo de esta flotilla y nada de otra.
    useMarcaStore().almacen.borrarTodo()
    token.value = null
    cliente.value = null
  }

  return { token, cliente, activa, primerNombre, restaurar, entrar, salir }
})
