<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiConfirmDialog from '@/components/ui/UiConfirmDialog.vue'

interface Direccion {
  id_direccion: number
  alias: string | null
  calle: string
  numero: string | null
  colonia: string | null
  ciudad: string | null
}

const route = useRoute()
const slug = computed(() => route.params.slug as string)
const clienteId = route.params.id as string

const clienteNombre = ref('')
const direcciones = ref<Direccion[]>([])
const loading = ref(false)
const error = ref('')
const direccionAEliminar = ref<Direccion | null>(null)
const eliminando = ref(false)

async function fetchDatos() {
  loading.value = true
  error.value = ''

  try {
    const [cliente, listado] = await Promise.all([
      http.get(`/t/${slug.value}/clientes/${clienteId}`),
      http.get(`/t/${slug.value}/clientes/${clienteId}/direcciones`),
    ])
    clienteNombre.value = (cliente.data.data ?? cliente.data).nombre
    direcciones.value = listado.data.data ?? listado.data
  } catch {
    error.value = 'No se pudo cargar las direcciones del cliente.'
  } finally {
    loading.value = false
  }
}

function requestEliminar(direccion: Direccion) {
  direccionAEliminar.value = direccion
}

function cancelEliminar() {
  direccionAEliminar.value = null
}

async function confirmEliminar() {
  const direccion = direccionAEliminar.value
  if (!direccion) return
  direccionAEliminar.value = null

  eliminando.value = true
  try {
    await http.delete(
      `/t/${slug.value}/clientes/${clienteId}/direcciones/${direccion.id_direccion}`,
    )
    direcciones.value = direcciones.value.filter((d) => d.id_direccion !== direccion.id_direccion)
  } catch {
    error.value = 'No se pudo eliminar la dirección.'
  } finally {
    eliminando.value = false
  }
}

onMounted(fetchDatos)
</script>

<template>
  <TenantLayout>
    <UiCard :title="clienteNombre ? `Direcciones de ${clienteNombre}` : 'Direcciones'">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <RouterLink
          :to="{ name: 'tenant-clientes-lista', params: { slug } }"
          class="text-sm font-medium text-brand-blue hover:underline"
        >
          &larr; Volver a clientes
        </RouterLink>
        <RouterLink
          :to="{ name: 'tenant-direcciones-crear', params: { slug, id: clienteId } }"
          class="rounded-lg bg-brand-blue px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark"
        >
          Nueva dirección
        </RouterLink>
      </div>

      <p v-if="error" role="alert" class="mb-4 text-sm text-red-600">{{ error }}</p>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-left text-sm">
          <thead>
            <tr
              class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
            >
              <th class="py-2 pr-4">Alias</th>
              <th class="py-2 pr-4">Calle y número</th>
              <th class="py-2 pr-4">Ciudad</th>
              <th class="py-2 pr-4">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="4" class="py-6 text-center text-black/50">Cargando...</td>
            </tr>
            <tr v-else-if="direcciones.length === 0">
              <td colspan="4" class="py-6 text-center text-black/50">No hay direcciones.</td>
            </tr>
            <tr
              v-for="direccion in direcciones"
              v-else
              :key="direccion.id_direccion"
              class="border-b border-gray-100 text-brand-dark"
            >
              <td class="py-2 pr-4 font-medium">{{ direccion.alias ?? '—' }}</td>
              <td class="py-2 pr-4">
                {{ [direccion.calle, direccion.numero].filter(Boolean).join(' ') }}
              </td>
              <td class="py-2 pr-4">{{ direccion.ciudad ?? '—' }}</td>
              <td class="py-2 pr-4">
                <div class="flex flex-wrap gap-2">
                  <RouterLink
                    :to="{
                      name: 'tenant-direcciones-editar',
                      params: { slug, id: clienteId, direccionId: direccion.id_direccion },
                    }"
                    class="rounded-lg bg-brand-blue px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark"
                  >
                    Editar
                  </RouterLink>
                  <button
                    type="button"
                    :disabled="eliminando"
                    class="rounded-lg border border-red-600 px-3 py-1.5 text-sm font-semibold text-red-600 transition-colors hover:bg-red-600 hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
                    @click="requestEliminar(direccion)"
                  >
                    Eliminar
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </UiCard>

    <UiConfirmDialog
      :open="direccionAEliminar !== null"
      title="Eliminar dirección"
      :message="
        direccionAEliminar
          ? `Esta acción es irreversible: se eliminará la dirección ${direccionAEliminar.alias ?? direccionAEliminar.calle}.`
          : ''
      "
      confirm-label="Eliminar"
      @confirm="confirmEliminar"
      @cancel="cancelEliminar"
    />
  </TenantLayout>
</template>
