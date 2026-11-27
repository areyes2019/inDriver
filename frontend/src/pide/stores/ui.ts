import { defineStore } from 'pinia'
import { ref } from 'vue'

/** Hojas y avisos que flotan sobre cualquier pantalla. */
export const useUiStore = defineStore('pide-ui', () => {
  const hojaAvisos = ref(false)
  const mensaje = ref<{ texto: string; tipo: 'info' | 'error' } | null>(null)
  let temporizador: ReturnType<typeof setTimeout> | null = null

  function avisar(texto: string, tipo: 'info' | 'error' = 'info'): void {
    mensaje.value = { texto, tipo }
    if (temporizador) clearTimeout(temporizador)
    temporizador = setTimeout(() => (mensaje.value = null), 3200)
  }

  return { hojaAvisos, mensaje, avisar }
})
