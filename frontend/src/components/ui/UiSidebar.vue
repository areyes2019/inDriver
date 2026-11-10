<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { Icon } from '@iconify/vue'

/**
 * Menú lateral del tenant (spec tenant/032): un riel de solo iconos que se despliega hacia la
 * derecha y arranca cerrado. Flota sobre el contenido: abrirlo no mueve nada de lo que hay debajo.
 *
 * Sus medidas salen de las variables CSS que define `TenantLayout` (`--riel-ancho`,
 * `--riel-ancho-abierto`, `--riel-margen`, `--alto-banda`).
 */
export interface ItemSidebar {
  label: string
  to: string
  icon: string
  /** Contador rojo sobre el icono; con 0 o sin él no se ve nada. */
  badge?: number
  /** Activo solo con la ruta exacta. Panel lo necesita: todas las demás rutas empiezan igual. */
  exacto?: boolean
}

/**
 * Botón del Panel que abre y cierra un panel sobre el mapa, sin cambiar de pantalla (spec 032, §4):
 * Nueva Entrega y Viajes en turno.
 */
export interface AccionSidebar {
  id: string
  label: string
  icon: string
  /** Si su panel está abierto: pinta `aria-expanded` y, si no es destacada, el fondo de activo. */
  expandida: boolean
  /** Fondo de acento. Solo el + de Nueva Entrega. */
  destacada?: boolean
  /** Contador rojo sobre el icono; con 0 o sin él no se ve nada. */
  badge?: number
  /** Punto ámbar (conectando) o rojo (caído) sobre el icono; en vivo no se ve (spec 027, RN-20). */
  conexion?: 'conectando' | 'vivo' | 'caido'
}

withDefaults(
  defineProps<{
    logoText: string
    items: ItemSidebar[]
    acciones?: AccionSidebar[]
    usuario?: { nombre: string; correo: string; iniciales: string } | null
    mostrarConfiguracion?: boolean
  }>(),
  {
    acciones: () => [],
    usuario: null,
    mostrarConfiguracion: true,
  },
)

const emit = defineEmits<{
  accion: [id: string]
  'accion-teclado': [id: string, event: KeyboardEvent]
  configuracion: []
  'cambiar-password': []
  'cerrar-sesion': []
}>()

const route = useRoute()
const raizRef = ref<HTMLElement>()
const botonesAccion = new Map<string, HTMLButtonElement>()

function registrarBotonAccion(id: string, el: unknown) {
  if (el instanceof HTMLButtonElement) botonesAccion.set(id, el)
  else botonesAccion.delete(id)
}

function etiquetaAccion(accion: AccionSidebar): string {
  const partes = [accion.label]
  if (accion.badge) partes.push(`${accion.badge} pendientes`)
  if (accion.conexion === 'caido') partes.push('sin conexión')
  if (accion.conexion === 'conectando') partes.push('reconectando')
  return partes.length > 1 ? `${partes[0]} (${partes.slice(1).join(', ')})` : accion.label
}

// Siempre arranca cerrado y no se recuerda entre cargas (spec 032, RN-02).
const abierto = ref(false)
const menuUsuarioAbierto = ref(false)

const claseGlobo =
  'pointer-events-none absolute left-full top-1/2 z-10 ml-3 -translate-y-1/2 whitespace-nowrap rounded-md bg-heading px-2 py-1 text-xs font-medium text-white opacity-0 shadow-md transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100'

function alternar() {
  abierto.value = !abierto.value
  menuUsuarioAbierto.value = false
}

function cerrarTodo() {
  abierto.value = false
  menuUsuarioAbierto.value = false
}

function onAccion(id: string) {
  abierto.value = false
  emit('accion', id)
}

function elegirDelMenuUsuario(evento: 'cambiar-password' | 'cerrar-sesion') {
  cerrarTodo()
  if (evento === 'cambiar-password') emit('cambiar-password')
  else emit('cerrar-sesion')
}

function etiquetaItem(item: ItemSidebar): string {
  return item.badge ? `${item.label} (${item.badge} esperando)` : item.label
}

function onTeclaDocumento(event: KeyboardEvent) {
  if (event.key !== 'Escape') return
  // El menú del usuario va encima del riel: Escape cierra primero lo de más arriba.
  if (menuUsuarioAbierto.value) {
    menuUsuarioAbierto.value = false
    return
  }
  abierto.value = false
}

function onPunteroDocumento(event: PointerEvent) {
  if (!abierto.value && !menuUsuarioAbierto.value) return
  if (raizRef.value?.contains(event.target as Node)) return
  cerrarTodo()
}

onMounted(() => {
  document.addEventListener('keydown', onTeclaDocumento)
  document.addEventListener('pointerdown', onPunteroDocumento)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onTeclaDocumento)
  document.removeEventListener('pointerdown', onPunteroDocumento)
})

// Elegir un destino lo cierra.
watch(() => route.fullPath, cerrarTodo)

defineExpose({
  focusAccion: (id: string) => botonesAccion.get(id)?.focus(),
})
</script>

<template>
  <nav
    ref="raizRef"
    aria-label="Menú principal"
    class="fixed bottom-(--riel-margen) left-(--riel-margen) top-[calc(var(--alto-banda)+var(--riel-margen))] z-40 rounded-lg bg-sidebar text-sidebar-texto shadow-xl transition-[width] duration-300 ease-in-out"
    :class="abierto ? 'w-(--riel-ancho-abierto)' : 'w-(--riel-ancho)'"
  >
    <!--
      Abierto, el contenido se recorta mientras el ancho crece, para que los nombres no se salgan de
      la tarjeta durante la animación. Cerrado no se recorta: los globos con el nombre de cada icono
      se dibujan fuera del riel.
    -->
    <div class="flex h-full flex-col px-2 py-3" :class="abierto ? 'overflow-hidden' : ''">
      <div class="flex h-11 shrink-0 items-center">
        <span class="flex h-11 w-12 shrink-0 items-center justify-center">
          <span class="flex h-9 w-9 items-center justify-center rounded-md bg-accent text-white">
            <Icon icon="tabler:bolt" width="20" height="20" aria-hidden="true" />
          </span>
        </span>
        <span v-if="abierto" class="truncate font-heading text-lg font-bold text-white">
          {{ logoText }}
        </span>
      </div>

      <button
        type="button"
        class="group relative mt-2 flex h-11 w-full shrink-0 items-center rounded-md transition-colors hover:bg-sidebar-hover hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
        :aria-expanded="abierto"
        :aria-label="abierto ? 'Cerrar menú' : 'Abrir menú'"
        @click="alternar"
      >
        <span class="flex h-11 w-12 shrink-0 items-center justify-center">
          <Icon
            :icon="
              abierto ? 'tabler:layout-sidebar-left-collapse' : 'tabler:layout-sidebar-left-expand'
            "
            width="20"
            height="20"
            aria-hidden="true"
          />
        </span>
        <span v-if="abierto" class="truncate text-sm font-medium">Cerrar menú</span>
        <span v-else aria-hidden="true" :class="claseGlobo">Abrir menú</span>
      </button>

      <button
        v-for="accion in acciones"
        :key="accion.id"
        :ref="(el) => registrarBotonAccion(accion.id, el)"
        type="button"
        class="group relative mt-2 flex h-11 w-full shrink-0 items-center rounded-md transition-colors focus:outline-none"
        :class="
          accion.destacada
            ? 'bg-accent text-white hover:bg-accent-hover focus-visible:ring-2 focus-visible:ring-white'
            : [
                'focus-visible:ring-2 focus-visible:ring-white/70',
                accion.expandida
                  ? 'bg-sidebar-activo text-white'
                  : 'hover:bg-sidebar-hover hover:text-white',
              ]
        "
        :aria-expanded="accion.expandida"
        :aria-label="abierto ? undefined : etiquetaAccion(accion)"
        @click="onAccion(accion.id)"
        @keydown="emit('accion-teclado', accion.id, $event)"
      >
        <span class="relative flex h-11 w-12 shrink-0 items-center justify-center">
          <Icon :icon="accion.icon" width="20" height="20" aria-hidden="true" />
          <span
            v-if="accion.badge"
            aria-hidden="true"
            class="absolute right-1 top-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold leading-none text-white"
            >{{ accion.badge }}</span
          >
          <span
            v-if="accion.conexion && accion.conexion !== 'vivo'"
            aria-hidden="true"
            class="absolute bottom-1.5 right-2 h-2.5 w-2.5 rounded-full ring-2 ring-sidebar"
            :class="accion.conexion === 'caido' ? 'bg-red-500' : 'bg-amber-400'"
          ></span>
        </span>
        <span v-if="abierto" class="min-w-0 flex-1 truncate text-left text-sm font-semibold">
          {{ etiquetaAccion(accion) }}
        </span>
        <span v-else aria-hidden="true" :class="claseGlobo">{{ etiquetaAccion(accion) }}</span>
      </button>

      <ul class="mt-3 flex flex-col gap-1 border-t border-sidebar-borde pt-3">
        <li v-for="item in items" :key="item.to">
          <RouterLink v-slot="{ href, navigate, isActive, isExactActive }" :to="item.to" custom>
            <a
              :href="href"
              class="group relative flex h-11 items-center rounded-md transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
              :class="
                (item.exacto ? isExactActive : isActive)
                  ? 'bg-sidebar-activo text-white'
                  : 'hover:bg-sidebar-hover hover:text-white'
              "
              :aria-current="(item.exacto ? isExactActive : isActive) ? 'page' : undefined"
              :aria-label="abierto ? undefined : etiquetaItem(item)"
              @click="navigate"
            >
              <span class="relative flex h-11 w-12 shrink-0 items-center justify-center">
                <Icon :icon="item.icon" width="20" height="20" aria-hidden="true" />
                <span
                  v-if="item.badge && !abierto"
                  aria-hidden="true"
                  class="absolute right-1 top-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold leading-none text-white"
                  >{{ item.badge }}</span
                >
              </span>
              <template v-if="abierto">
                <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ item.label }}</span>
                <span
                  v-if="item.badge"
                  class="mr-2 inline-flex min-w-5 items-center justify-center rounded-full bg-red-600 px-1.5 text-xs font-semibold text-white"
                  :aria-label="`${item.badge} esperando`"
                  >{{ item.badge }}</span
                >
              </template>
              <span v-else aria-hidden="true" :class="claseGlobo">{{ item.label }}</span>
            </a>
          </RouterLink>
        </li>
      </ul>

      <div class="mt-auto flex flex-col gap-1 border-t border-sidebar-borde pt-3">
        <button
          v-if="mostrarConfiguracion"
          type="button"
          class="group relative flex h-11 w-full items-center rounded-md transition-colors hover:bg-sidebar-hover hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
          :aria-label="abierto ? undefined : 'Configuración'"
          @click="emit('configuracion')"
        >
          <span class="flex h-11 w-12 shrink-0 items-center justify-center">
            <Icon icon="tabler:settings" width="20" height="20" aria-hidden="true" />
          </span>
          <span v-if="abierto" class="truncate text-sm font-medium">Configuración</span>
          <span v-else aria-hidden="true" :class="claseGlobo">Configuración</span>
        </button>

        <div class="flex h-11 items-center" :class="abierto ? 'px-2' : 'justify-center'">
          <slot name="pie" :abierto="abierto" />
        </div>

        <button
          v-if="usuario"
          type="button"
          class="group relative flex h-12 w-full items-center rounded-md text-left transition-colors hover:bg-sidebar-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
          aria-haspopup="menu"
          :aria-expanded="menuUsuarioAbierto"
          :aria-label="`Cuenta de ${usuario.nombre}`"
          @click="menuUsuarioAbierto = !menuUsuarioAbierto"
        >
          <span class="flex h-12 w-12 shrink-0 items-center justify-center">
            <span
              class="flex h-9 w-9 items-center justify-center rounded-full bg-accent-soft text-sm font-semibold text-accent"
              >{{ usuario.iniciales }}</span
            >
          </span>
          <span v-if="abierto" class="min-w-0 flex-1 pr-2">
            <span class="block truncate text-sm font-semibold text-white">{{
              usuario.nombre
            }}</span>
            <span class="block truncate text-xs">{{ usuario.correo }}</span>
          </span>
          <span v-else-if="!menuUsuarioAbierto" aria-hidden="true" :class="claseGlobo">
            {{ usuario.nombre }}
          </span>
        </button>
      </div>
    </div>

    <!-- Fuera del contenedor recortado: se dibuja a la derecha del riel, abierto o cerrado. -->
    <div
      v-if="usuario && menuUsuarioAbierto"
      role="menu"
      :aria-label="`Cuenta de ${usuario.nombre}`"
      class="absolute bottom-0 left-full z-[45] ml-3 w-64 rounded-lg border border-default bg-white p-2 text-heading shadow-xl"
    >
      <div class="flex items-center gap-3 border-b border-default px-2 pb-3 pt-1">
        <span
          class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-sm font-semibold text-accent"
          >{{ usuario.iniciales }}</span
        >
        <span class="min-w-0">
          <span class="block truncate text-sm font-semibold">{{ usuario.nombre }}</span>
          <span class="block truncate text-xs text-body">{{ usuario.correo }}</span>
        </span>
      </div>
      <button
        type="button"
        role="menuitem"
        class="mt-1 flex w-full items-center gap-3 rounded-md px-2 py-2 text-sm hover:bg-black/5"
        @click="elegirDelMenuUsuario('cambiar-password')"
      >
        <Icon icon="tabler:key" width="18" height="18" aria-hidden="true" />
        Cambiar contraseña
      </button>
      <button
        type="button"
        role="menuitem"
        class="flex w-full items-center gap-3 rounded-md px-2 py-2 text-sm hover:bg-black/5"
        @click="elegirDelMenuUsuario('cerrar-sesion')"
      >
        <Icon icon="tabler:logout" width="18" height="18" aria-hidden="true" />
        Cerrar sesión
      </button>
    </div>
  </nav>
</template>
