<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiConfirmDialog from '@/components/ui/UiConfirmDialog.vue'

interface Usuario {
  id_usuario: number
  nombre: string
  apellido_paterno: string
  apellido_materno: string | null
  telefono: string | null
  email: string
  rol: string
  estado: string
  created_at: string
}

const route = useRoute()
const slug = computed(() => route.params.slug as string)

const usuarios = ref<Usuario[]>([])
const search = ref('')
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')
const usuarioToDelete = ref<Usuario | null>(null)
const deleting = ref(false)
const deleteError = ref('')

const estadoColor: Record<string, 'green' | 'yellow' | 'blue'> = {
  Activo: 'green',
  Suspendido: 'yellow',
  Inactivo: 'blue',
}

async function fetchUsuarios() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await http.get(`/t/${slug.value}/usuarios`, {
      params: { search: search.value || undefined, page: page.value },
    })
    usuarios.value = data.data
    lastPage.value = data.meta?.last_page ?? 1
  } catch {
    error.value = 'No se pudo cargar la lista de usuarios.'
  } finally {
    loading.value = false
  }
}

function requestDelete(usuario: Usuario) {
  deleteError.value = ''
  usuarioToDelete.value = usuario
}

function cancelDelete() {
  usuarioToDelete.value = null
  deleteError.value = ''
}

async function confirmDelete(password?: string) {
  const usuario = usuarioToDelete.value
  if (!usuario) return

  deleting.value = true
  deleteError.value = ''
  try {
    await http.delete(`/t/${slug.value}/usuarios/${usuario.id_usuario}`, { data: { password } })
    usuarios.value = usuarios.value.filter((u) => u.id_usuario !== usuario.id_usuario)
    usuarioToDelete.value = null
  } catch (err) {
    deleteError.value =
      (axios.isAxiosError(err) && err.response?.data?.errors?.password?.[0]) ??
      'No se pudo eliminar el usuario.'
  } finally {
    deleting.value = false
  }
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined

watch(search, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    page.value = 1
    fetchUsuarios()
  }, 300)
})

watch(page, () => fetchUsuarios())

onMounted(fetchUsuarios)
</script>

<template>
  <TenantLayout>
    <UiCard title="Usuarios">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <input
          v-model="search"
          type="search"
          placeholder="Buscar por nombre o email..."
          class="w-full max-w-sm rounded-lg border border-gray-300 px-3 py-2 text-sm text-brand-dark placeholder:text-gray-400 focus:border-brand-blue focus:ring-1 focus:ring-brand-blue focus:outline-none"
        />
        <RouterLink
          :to="{ name: 'tenant-usuarios-crear', params: { slug } }"
          class="rounded-lg bg-brand-blue px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark"
        >
          Nuevo usuario
        </RouterLink>
      </div>

      <p v-if="error" role="alert" class="mb-4 text-sm text-red-600">{{ error }}</p>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-left text-sm">
          <thead>
            <tr
              class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
            >
              <th class="py-2 pr-4">Nombre</th>
              <th class="py-2 pr-4">Email</th>
              <th class="py-2 pr-4">Rol</th>
              <th class="py-2 pr-4">Estado</th>
              <th class="py-2 pr-4">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="5" class="py-6 text-center text-black/50">Cargando...</td>
            </tr>
            <tr v-else-if="usuarios.length === 0">
              <td colspan="5" class="py-6 text-center text-black/50">No hay usuarios.</td>
            </tr>
            <tr
              v-for="usuario in usuarios"
              v-else
              :key="usuario.id_usuario"
              class="border-b border-gray-100 text-brand-dark"
            >
              <td class="py-2 pr-4 font-medium">
                {{ usuario.nombre }} {{ usuario.apellido_paterno }}
              </td>
              <td class="py-2 pr-4">{{ usuario.email }}</td>
              <td class="py-2 pr-4">{{ usuario.rol }}</td>
              <td class="py-2 pr-4">
                <UiBadge :text="usuario.estado" :color="estadoColor[usuario.estado] ?? 'blue'" />
              </td>
              <td class="py-2 pr-4">
                <div class="flex flex-wrap gap-2">
                  <RouterLink
                    :to="{ name: 'tenant-usuarios-editar', params: { slug, id: usuario.id_usuario } }"
                    class="rounded-lg bg-brand-blue px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark"
                  >
                    Editar
                  </RouterLink>
                  <button
                    type="button"
                    class="rounded-lg border border-red-600 px-3 py-1.5 text-sm font-semibold text-red-600 transition-colors hover:bg-red-600 hover:text-white"
                    @click="requestDelete(usuario)"
                  >
                    Eliminar
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
          class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-brand-dark disabled:cursor-not-allowed disabled:opacity-50"
          @click="page -= 1"
        >
          Anterior
        </button>
        <span class="text-sm text-black/60">Página {{ page }} de {{ lastPage }}</span>
        <button
          type="button"
          :disabled="page >= lastPage"
          class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-brand-dark disabled:cursor-not-allowed disabled:opacity-50"
          @click="page += 1"
        >
          Siguiente
        </button>
      </div>
    </UiCard>

    <UiConfirmDialog
      :open="usuarioToDelete !== null"
      title="Eliminar usuario"
      :message="
        usuarioToDelete
          ? `Esta acción es irreversible: se eliminará a ${usuarioToDelete.nombre} ${usuarioToDelete.apellido_paterno}. Ingresa tu contraseña para confirmar.`
          : ''
      "
      confirm-label="Eliminar"
      require-password
      :password-error="deleteError"
      @confirm="confirmDelete"
      @cancel="cancelDelete"
    />
  </TenantLayout>
</template>
