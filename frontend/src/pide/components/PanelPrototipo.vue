<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { controlesPrototipo, reanudarConexionSimulada } from '../api/mock'
import { useMarcaStore } from '../stores/marca'
import { useSesionStore } from '../stores/sesion'
import { useUiStore } from '../stores/ui'
import HojaInferior from './HojaInferior.vue'

/**
 * Solo prototipo (Fase 2): botón "Demo" para revisar los casos especiales sin backend. No existe
 * con la API real.
 */
const abierto = ref(false)
const marca = useMarcaStore()
const sesion = useSesionStore()
const ui = useUiStore()
const route = useRoute()
const router = useRouter()

async function recargarMarca(): Promise<void> {
  await marca.cargar(marca.slug, true)
  await router.replace(route.fullPath)
}

function alternarConexion(): void {
  if (controlesPrototipo.conexionCortada) reanudarConexionSimulada()
  else controlesPrototipo.conexionCortada = true
}

function verInvitacion(): void {
  abierto.value = false
  ui.hojaInstalar = true
}

function otraFlotilla(): void {
  const destino = marca.slug === 'motopanda' ? 'mandados-del-bajio' : 'motopanda'
  window.location.href = `/pide/${destino}/`
}

async function reiniciar(): Promise<void> {
  marca.almacen.borrarTodo()
  await sesion.salir()
  window.location.href = `/pide/${marca.slug}/`
}

const opciones = [
  {
    texto: 'App apagada en la flotilla',
    nota: 'RN-01',
    valor: () => controlesPrototipo.appApagada,
    cambiar: async () => {
      controlesPrototipo.appApagada = !controlesPrototipo.appApagada
      await recargarMarca()
    },
  },
  {
    texto: 'Fuera de horario',
    nota: 'RN-29',
    valor: () => controlesPrototipo.fueraDeHorario,
    cambiar: async () => {
      controlesPrototipo.fueraDeHorario = !controlesPrototipo.fueraDeHorario
      await recargarMarca()
    },
  },
  {
    texto: 'Cortar la conexión en tiempo real',
    nota: 'RN-47',
    valor: () => controlesPrototipo.conexionCortada,
    cambiar: alternarConexion,
  },
  {
    texto: 'Avisar de una versión nueva',
    nota: 'RN-07',
    valor: () => controlesPrototipo.versionNueva,
    cambiar: () => (controlesPrototipo.versionNueva = !controlesPrototipo.versionNueva),
  },
]
</script>

<template>
  <button
    type="button"
    class="fixed top-1/3 right-0 z-40 rounded-l-xl bg-tinta/70 px-1 py-3 text-[0.6rem] font-semibold tracking-wider text-white uppercase shadow-flotante [writing-mode:vertical-rl]"
    @click="abierto = true"
  >
    Demo
  </button>

  <HojaInferior v-model="abierto" titulo="Prototipo">
    <p class="-mt-2 mb-3 text-sm text-suave">
      Datos simulados. Código de acceso: cualquiera de 6 dígitos (<strong>000000</strong> falla).
      Cliente con historial: <strong>maria@ejemplo.com</strong>. Un correo en
      <strong>gmial.com</strong> simula un dominio mal escrito.
    </p>
    <ul class="divide-y divide-linea">
      <li v-for="o in opciones" :key="o.texto">
        <label class="flex min-h-12 cursor-pointer items-center gap-3">
          <input
            type="checkbox"
            class="size-5 accent-morado-700"
            :checked="o.valor()"
            @change="o.cambiar()"
          />
          <span class="text-sm text-tinta">{{ o.texto }}</span>
          <span class="ml-auto text-xs text-suave">{{ o.nota }}</span>
        </label>
      </li>
    </ul>
    <div class="mt-3 grid grid-cols-2 gap-2 pb-2 text-sm font-semibold">
      <button
        type="button"
        class="min-h-12 rounded-xl bg-morado-50 text-morado-700"
        @click="verInvitacion"
      >
        Ver invitación a instalar
      </button>
      <button
        type="button"
        class="min-h-12 rounded-xl bg-morado-50 text-morado-700"
        @click="otraFlotilla"
      >
        Abrir otra flotilla
      </button>
      <button
        type="button"
        class="col-span-2 min-h-12 rounded-xl bg-red-50 text-peligro"
        @click="reiniciar"
      >
        Reiniciar demo (cerrar sesión)
      </button>
    </div>
  </HojaInferior>
</template>
