<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiConfirmDialog from '@/components/ui/UiConfirmDialog.vue'

interface Pedido {
  id_pedido: number
  numero_pedido: string
  cliente_nombre: string | null
  nombre_solicitante: string
  telefono_solicitante: string
  fecha_servicio: string
  estado: string
}

const route = useRoute()
const slug = computed(() => route.params.slug as string)

const pedidos = ref<Pedido[]>([])
const search = ref('')
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')
const cambiandoEstado = ref(false)

const estadoColor: Record<string, 'gray' | 'blue' | 'orange' | 'green' | 'red'> = {
  PENDIENTE: 'gray',
  PUBLICADO: 'blue',
  TOMADO: 'orange',
  ARRIBADO: 'orange',
  EN_CAMINO: 'blue',
  ARRIBADO_A_ENTREGA: 'blue',
  ENTREGADO: 'green',
  RECHAZADO: 'red',
  CANCELADO: 'red',
}

const siguienteEstado: Record<string, { estado: string; label: string } | undefined> = {
  PENDIENTE: { estado: 'PUBLICADO', label: 'Publicar' },
  PUBLICADO: { estado: 'TOMADO', label: 'Marcar tomado' },
  TOMADO: { estado: 'ARRIBADO', label: 'Marcar arribo' },
  ARRIBADO: { estado: 'EN_CAMINO', label: 'Iniciar viaje' },
  EN_CAMINO: { estado: 'ARRIBADO_A_ENTREGA', label: 'Arribo a entrega' },
  ARRIBADO_A_ENTREGA: { estado: 'ENTREGADO', label: 'Entregar' },
}

const estadosFinales = ['ENTREGADO', 'CANCELADO', 'RECHAZADO']

function puedeCancelar(estado: string) {
  return !estadosFinales.includes(estado)
}

const pedidoParaCambiarEstado = ref<Pedido | null>(null)
const estadoDestino = ref('')

async function fetchPedidos() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await http.get(`/t/${slug.value}/pedidos`, {
      params: { search: search.value || undefined, page: page.value },
    })
    pedidos.value = data.data
    lastPage.value = data.meta?.last_page ?? 1
  } catch {
    error.value = 'No se pudo cargar la lista de pedidos.'
  } finally {
    loading.value = false
  }
}

function requestCambioEstado(pedido: Pedido, estado: string) {
  pedidoParaCambiarEstado.value = pedido
  estadoDestino.value = estado
}

function cancelCambioEstado() {
  pedidoParaCambiarEstado.value = null
  estadoDestino.value = ''
}

async function confirmCambioEstado() {
  const pedido = pedidoParaCambiarEstado.value
  const estado = estadoDestino.value
  if (!pedido || !estado) return
  pedidoParaCambiarEstado.value = null
  estadoDestino.value = ''

  cambiandoEstado.value = true
  try {
    const { data } = await http.patch(`/t/${slug.value}/pedidos/${pedido.id_pedido}/estado`, {
      estado,
    })
    const updated = data.data ?? data
    const index = pedidos.value.findIndex((p) => p.id_pedido === pedido.id_pedido)
    if (index !== -1) pedidos.value[index] = updated
  } catch {
    error.value = 'No se pudo cambiar el estado del pedido.'
  } finally {
    cambiandoEstado.value = false
  }
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined

watch(search, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    page.value = 1
    fetchPedidos()
  }, 300)
})

watch(page, () => fetchPedidos())

onMounted(fetchPedidos)
</script>

<template>
  <TenantLayout>
    <UiCard title="Pedidos">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <input
          v-model="search"
          type="search"
          placeholder="Buscar por número, solicitante o teléfono..."
          class="w-full max-w-sm rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
        />
        <RouterLink
          :to="{ name: 'tenant-pedidos-crear', params: { slug } }"
          class="rounded-lg bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-heading"
        >
          Nuevo pedido
        </RouterLink>
      </div>

      <p v-if="error" role="alert" class="mb-4 text-sm text-red-600">{{ error }}</p>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[900px] text-left text-sm">
          <thead>
            <tr
              class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
            >
              <th class="py-2 pr-4">Número</th>
              <th class="py-2 pr-4">Solicitante</th>
              <th class="py-2 pr-4">Cliente</th>
              <th class="py-2 pr-4">Fecha de servicio</th>
              <th class="py-2 pr-4">Estado</th>
              <th class="py-2 pr-4">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="6" class="py-6 text-center text-black/50">Cargando...</td>
            </tr>
            <tr v-else-if="pedidos.length === 0">
              <td colspan="6" class="py-6 text-center text-black/50">No hay pedidos.</td>
            </tr>
            <tr
              v-for="pedido in pedidos"
              v-else
              :key="pedido.id_pedido"
              class="border-b border-gray-100 text-heading"
            >
              <td class="py-2 pr-4 font-medium">{{ pedido.numero_pedido }}</td>
              <td class="py-2 pr-4">
                {{ pedido.nombre_solicitante }}
                <span class="block text-xs text-black/50">{{ pedido.telefono_solicitante }}</span>
              </td>
              <td class="py-2 pr-4">{{ pedido.cliente_nombre ?? '—' }}</td>
              <td class="py-2 pr-4">{{ pedido.fecha_servicio }}</td>
              <td class="py-2 pr-4">
                <UiBadge :text="pedido.estado" :color="estadoColor[pedido.estado] ?? 'gray'" />
              </td>
              <td class="py-2 pr-4">
                <div class="flex flex-wrap gap-2">
                  <RouterLink
                    :to="{ name: 'tenant-pedidos-editar', params: { slug, id: pedido.id_pedido } }"
                    class="rounded-lg bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-heading"
                  >
                    Editar
                  </RouterLink>
                  <button
                    v-if="siguienteEstado[pedido.estado]"
                    type="button"
                    :disabled="cambiandoEstado"
                    class="rounded-lg border border-accent px-3 py-1.5 text-sm font-semibold text-accent transition-colors hover:bg-accent hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
                    @click="requestCambioEstado(pedido, siguienteEstado[pedido.estado]!.estado)"
                  >
                    {{ siguienteEstado[pedido.estado]!.label }}
                  </button>
                  <button
                    v-if="puedeCancelar(pedido.estado)"
                    type="button"
                    :disabled="cambiandoEstado"
                    class="rounded-lg border border-red-600 px-3 py-1.5 text-sm font-semibold text-red-600 transition-colors hover:bg-red-600 hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
                    @click="requestCambioEstado(pedido, 'CANCELADO')"
                  >
                    Cancelar
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="lastPage > 1" class="mt-4 flex items-center gap-3">
        <button
          type="button"
          :disabled="page <= 1"
          class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-heading disabled:cursor-not-allowed disabled:opacity-50"
          @click="page -= 1"
        >
          Anterior
        </button>
        <span class="text-sm text-black/60">Página {{ page }} de {{ lastPage }}</span>
        <button
          type="button"
          :disabled="page >= lastPage"
          class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-heading disabled:cursor-not-allowed disabled:opacity-50"
          @click="page += 1"
        >
          Siguiente
        </button>
      </div>
    </UiCard>

    <UiConfirmDialog
      :open="pedidoParaCambiarEstado !== null"
      title="Cambiar estado del pedido"
      :message="
        pedidoParaCambiarEstado
          ? `El pedido ${pedidoParaCambiarEstado.numero_pedido} pasará a estado ${estadoDestino}.`
          : ''
      "
      confirm-label="Confirmar"
      @confirm="confirmCambioEstado"
      @cancel="cancelCambioEstado"
    />
  </TenantLayout>
</template>
