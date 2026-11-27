import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '../api'
import type { Notificacion } from '../api/tipos'

/** La campanita (RN-51). */
export const useNotificacionesStore = defineStore('pide-notificaciones', () => {
  const lista = ref<Notificacion[]>([])
  const sinLeer = computed(() => lista.value.filter((n) => !n.leida).length)

  async function cargar(): Promise<void> {
    lista.value = await api.notificaciones()
  }

  async function marcarTodas(): Promise<void> {
    const pendientes = lista.value.filter((n) => !n.leida)
    pendientes.forEach((n) => (n.leida = true))
    await Promise.all(pendientes.map((n) => api.marcarLeida(n.id)))
  }

  return { lista, sinLeer, cargar, marcarTodas }
})
