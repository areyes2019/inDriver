<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import type { Punto } from '../api/tipos'
import BotonPrincipal from '../components/BotonPrincipal.vue'
import BuscadorDireccion from '../components/BuscadorDireccion.vue'
import CampoTexto from '../components/CampoTexto.vue'
import EncabezadoPantalla from '../components/EncabezadoPantalla.vue'
import { useMarcaStore } from '../stores/marca'
import { useUiStore } from '../stores/ui'

const route = useRoute()
const router = useRouter()
const marca = useMarcaStore()
const ui = useUiStore()

const esNueva = route.params.id === 'nueva'
const id = esNueva ? undefined : Number(route.params.id)

const alias = ref(String(route.query.alias ?? ''))
const punto = ref<Punto | null>(null)
const referencias = ref('')
const guardando = ref(false)
const borrando = ref(false)

const aliasFijo = computed(() => alias.value === 'Casa' || alias.value === 'Trabajo')
const valido = computed(() => alias.value.trim().length > 0 && punto.value !== null)

onMounted(async () => {
  if (esNueva) return
  const d = (await api.direcciones()).find((x) => x.id === id)
  if (!d) {
    router.replace({ name: 'direcciones', params: { slug: marca.slug } })
    return
  }
  alias.value = d.alias
  punto.value = { direccion: d.direccion, lat: d.lat, lng: d.lng }
  referencias.value = d.referencias
})

function volver(): void {
  router.push({ name: 'direcciones', params: { slug: marca.slug } })
}

async function guardar(): Promise<void> {
  if (!valido.value || !punto.value) return
  guardando.value = true
  try {
    await api.guardarDireccion({
      ...(id ? { id } : {}),
      alias: alias.value.trim(),
      ...punto.value,
      referencias: referencias.value.trim(),
    })
    ui.avisar('Dirección guardada')
    volver()
  } finally {
    guardando.value = false
  }
}

async function borrar(): Promise<void> {
  if (!id) return
  borrando.value = true
  try {
    await api.borrarDireccion(id)
    ui.avisar('Dirección eliminada')
    volver()
  } finally {
    borrando.value = false
  }
}
</script>

<template>
  <main class="pb-10">
    <EncabezadoPantalla
      :titulo="esNueva ? 'Nueva dirección' : alias"
      :volver-a="`/${marca.slug}/cuenta/direcciones`"
    />
    <form class="space-y-5 px-5" @submit.prevent="guardar">
      <CampoTexto
        v-if="!aliasFijo"
        v-model="alias"
        etiqueta="Nombre"
        placeholder="Ej. Casa de mi mamá"
        :maximo="40"
      />
      <BuscadorDireccion v-model="punto" etiqueta="Dirección" :con-ubicacion="true" />
      <CampoTexto
        v-model="referencias"
        etiqueta="Referencias (opcional)"
        placeholder="Ej. portón negro, timbre 2"
        :maximo="255"
      />
      <BotonPrincipal tipo="submit" :deshabilitado="!valido" :cargando="guardando">
        Guardar
      </BotonPrincipal>
      <BotonPrincipal
        v-if="!esNueva"
        variante="peligro"
        icono="tabler:trash"
        :cargando="borrando"
        @click="borrar"
      >
        Eliminar
      </BotonPrincipal>
    </form>
  </main>
</template>
