<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api'
import BotonPrincipal from '../components/BotonPrincipal.vue'
import CampoTexto from '../components/CampoTexto.vue'
import HojaInferior from '../components/HojaInferior.vue'
import { useInstalacion } from '../composables/useInstalacion'
import { esTelefonoValido, formatoTelefono, soloDiezDigitos } from '../lib/telefono'
import { useMarcaStore } from '../stores/marca'
import { useSesionStore } from '../stores/sesion'
import { useUiStore } from '../stores/ui'

const marca = useMarcaStore()
const sesion = useSesionStore()
const ui = useUiStore()
const router = useRouter()
const { instalada } = useInstalacion()

const editando = ref(false)
const guardando = ref(false)
const nombre = ref('')
const telefono = ref('')
const confirmarSalida = ref(false)

const telefonoVisible = computed({
  get: () => formatoTelefono(telefono.value),
  set: (v: string) => (telefono.value = soloDiezDigitos(v)),
})
const datosValidos = computed(
  () => nombre.value.trim().length >= 3 && esTelefonoValido(telefono.value),
)

const avisosActivos = computed(
  () => typeof Notification !== 'undefined' && Notification.permission === 'granted',
)

function abrirEdicion(): void {
  nombre.value = sesion.cliente?.nombre ?? ''
  telefono.value = sesion.cliente?.telefono ?? ''
  editando.value = true
}

/** RN-57: nombre y celular. El correo de acceso no se cambia desde aquí. */
async function guardar(): Promise<void> {
  if (!datosValidos.value) return
  guardando.value = true
  try {
    sesion.cliente = await api.actualizarPerfil({
      nombre: nombre.value.trim(),
      telefono: telefono.value,
    })
    editando.value = false
    ui.avisar('Datos guardados')
  } finally {
    guardando.value = false
  }
}

async function salir(): Promise<void> {
  await sesion.salir()
  router.replace({ name: 'registro', params: { slug: marca.slug } })
}
</script>

<template>
  <main class="pt-seguro px-5 pb-6">
    <h1 class="mt-3 font-titulo text-3xl font-extrabold">Mi cuenta</h1>

    <section class="mt-5 flex items-center gap-4 rounded-3xl bg-white p-4 ring-1 ring-linea">
      <span
        class="flex size-14 items-center justify-center rounded-full bg-morado-700 font-titulo text-xl font-bold text-white"
        aria-hidden="true"
      >
        {{ sesion.primerNombre.charAt(0) }}
      </span>
      <div class="min-w-0">
        <p class="truncate text-lg font-semibold">{{ sesion.cliente?.nombre }}</p>
        <p class="truncate text-sm text-suave">{{ sesion.cliente?.email }}</p>
        <p class="text-sm text-suave">+52 {{ formatoTelefono(sesion.cliente?.telefono ?? '') }}</p>
      </div>
      <button
        type="button"
        class="ml-auto flex size-11 items-center justify-center rounded-full text-morado-700 active:bg-morado-50"
        aria-label="Editar mis datos"
        @click="abrirEdicion"
      >
        <Icon icon="tabler:pencil" class="size-5" />
      </button>
    </section>

    <ul class="mt-4 divide-y divide-linea overflow-hidden rounded-3xl bg-white ring-1 ring-linea">
      <li>
        <RouterLink
          :to="{ name: 'direcciones', params: { slug: marca.slug } }"
          class="flex min-h-16 items-center gap-3 px-4 active:bg-morado-50"
        >
          <Icon icon="tabler:map-pin" class="size-6 text-morado-700" />
          <span class="font-medium">Mis direcciones</span>
          <Icon icon="tabler:chevron-right" class="ml-auto size-5 text-suave" />
        </RouterLink>
      </li>
      <li>
        <RouterLink
          :to="{ name: 'pedidos', params: { slug: marca.slug } }"
          class="flex min-h-16 items-center gap-3 px-4 active:bg-morado-50"
        >
          <Icon icon="tabler:list-details" class="size-6 text-morado-700" />
          <span class="font-medium">Historial de pedidos</span>
          <Icon icon="tabler:chevron-right" class="ml-auto size-5 text-suave" />
        </RouterLink>
      </li>
      <li>
        <button
          type="button"
          class="flex min-h-16 w-full items-center gap-3 px-4 text-left active:bg-morado-50"
          @click="ui.hojaAvisos = !avisosActivos"
        >
          <Icon icon="tabler:bell" class="size-6 text-morado-700" />
          <span class="font-medium">Avisos de mis pedidos</span>
          <span class="ml-auto text-sm" :class="avisosActivos ? 'text-exito' : 'text-suave'">
            {{ avisosActivos ? 'Activados' : 'Activar' }}
          </span>
        </button>
      </li>
      <li v-if="!instalada()">
        <button
          type="button"
          class="flex min-h-16 w-full items-center gap-3 px-4 text-left active:bg-morado-50"
          @click="ui.hojaInstalar = true"
        >
          <Icon icon="tabler:download" class="size-6 text-morado-700" />
          <span class="font-medium">Instalar la app</span>
          <Icon icon="tabler:chevron-right" class="ml-auto size-5 text-suave" />
        </button>
      </li>
    </ul>

    <a
      v-if="marca.marca?.telefonoContacto"
      :href="`tel:${marca.marca.telefonoContacto}`"
      class="mt-4 flex min-h-16 items-center gap-3 rounded-3xl bg-white px-4 ring-1 ring-linea"
    >
      <Icon icon="tabler:phone" class="size-6 text-morado-700" />
      <span class="font-medium">Llamar a {{ marca.nombre }}</span>
      <span class="ml-auto text-sm text-suave">{{
        formatoTelefono(marca.marca.telefonoContacto)
      }}</span>
    </a>

    <div class="mt-8">
      <BotonPrincipal variante="peligro" icono="tabler:logout" @click="confirmarSalida = true">
        Cerrar sesión
      </BotonPrincipal>
    </div>

    <HojaInferior v-model="editando" titulo="Mis datos">
      <form class="space-y-4 pb-2" @submit.prevent="guardar">
        <CampoTexto v-model="nombre" etiqueta="Nombre" autocompletar="name" />
        <CampoTexto
          v-model="telefonoVisible"
          etiqueta="Celular"
          tipo="tel"
          modo-teclado="tel"
          prefijo="+52"
          autocompletar="tel-national"
          ayuda="Tu repartidor te llama a este número"
        />
        <p class="text-sm text-suave">
          Entras con <strong>{{ sesion.cliente?.email }}</strong
          >. El correo no se puede cambiar desde aquí.
        </p>
        <BotonPrincipal tipo="submit" :cargando="guardando" :deshabilitado="!datosValidos">
          Guardar
        </BotonPrincipal>
      </form>
    </HojaInferior>

    <HojaInferior v-model="confirmarSalida" titulo="¿Cerrar sesión?">
      <p class="-mt-1 text-suave">Para volver a entrar te mandaremos un código a tu correo.</p>
      <div class="mt-5 space-y-2">
        <BotonPrincipal variante="peligro" @click="salir">Cerrar sesión</BotonPrincipal>
        <BotonPrincipal variante="texto" @click="confirmarSalida = false">Cancelar</BotonPrincipal>
      </div>
    </HojaInferior>
  </main>
</template>
