<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import http from '@/lib/http'
import UiAlert from '@/components/ui/UiAlert.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiConfirmDialog from '@/components/ui/UiConfirmDialog.vue'

/**
 * Pestaña "Por revisar" del módulo de clientes (spec tenant/035, 6.8 y RN-18). Teléfonos que comparten
 * varios clientes: el AdminCliente elige el principal y los unifica. El sistema nunca une solo.
 */
interface ClienteRevision {
  id_cliente: number
  nombre: string
  telefono: string | null
  email_acceso: string | null
  origen: 'PANEL' | 'WHATSAPP' | 'APP' | null
  pedidos: number
  ultimo_pedido_en: string | null
  created_at: string
}

interface Revision {
  telefono_e164: string
  detectado_en: string
  clientes: ClienteRevision[]
}

const props = defineProps<{ slug: string }>()

const revisiones = ref<Revision[]>([])
const cargando = ref(false)
const error = ref('')
const exito = ref('')
const principal = reactive<Record<string, number>>({})
const correo = reactive<Record<string, number>>({})
const porConfirmar = ref<Revision | null>(null)
const unificando = ref<string | null>(null)

const etiquetaOrigen: Record<string, string> = { PANEL: 'Panel', WHATSAPP: 'WhatsApp', APP: 'App' }

async function cargar(): Promise<void> {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await http.get(`/t/${props.slug}/clientes/por-revisar`)
    revisiones.value = data.data
    for (const r of revisiones.value) {
      // Por defecto, el que tiene más pedidos: casi siempre es el cliente "de siempre".
      const masPedidos = [...r.clientes].sort((a, b) => b.pedidos - a.pedidos)[0]
      if (masPedidos && principal[r.telefono_e164] === undefined) {
        principal[r.telefono_e164] = masPedidos.id_cliente
      }
      const conCorreo = r.clientes.filter((c) => c.email_acceso)
      if (conCorreo.length > 1 && correo[r.telefono_e164] === undefined) {
        correo[r.telefono_e164] = conCorreo[0]!.id_cliente
      }
    }
  } catch {
    error.value = 'No se pudo cargar la lista de teléfonos por revisar.'
  } finally {
    cargando.value = false
  }
}

function necesitaElegirCorreo(r: Revision): boolean {
  return r.clientes.filter((c) => c.email_acceso).length > 1
}

function nombrePrincipal(r: Revision): string {
  return r.clientes.find((c) => c.id_cliente === principal[r.telefono_e164])?.nombre ?? ''
}

function fecha(iso: string | null): string {
  return iso ? new Date(iso).toLocaleDateString('es-MX', { dateStyle: 'medium' }) : '—'
}

async function unificar(): Promise<void> {
  const r = porConfirmar.value
  porConfirmar.value = null
  if (!r) return

  unificando.value = r.telefono_e164
  error.value = ''
  exito.value = ''
  try {
    await http.post(`/t/${props.slug}/clientes/unificar`, {
      telefono_e164: r.telefono_e164,
      id_principal: principal[r.telefono_e164],
      id_correo: necesitaElegirCorreo(r) ? correo[r.telefono_e164] : null,
    })
    exito.value = `Listo: los clientes del ${r.telefono_e164} quedaron unificados en ${nombrePrincipal(r)}.`
    await cargar()
  } catch (e) {
    const mensaje = axios.isAxiosError(e) ? e.response?.data?.message : null
    error.value = mensaje ?? 'No se pudieron unificar los clientes.'
  } finally {
    unificando.value = null
  }
}

onMounted(cargar)
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm text-body">
      Estos teléfonos aparecen en más de un cliente. Elige cuál es el cliente principal: sus
      pedidos, direcciones y conversaciones quedarán juntos, y los demás se desactivan.
    </p>

    <UiAlert v-if="error" variant="error">{{ error }}</UiAlert>
    <UiAlert v-if="exito" variant="success">{{ exito }}</UiAlert>

    <p v-if="cargando" class="text-sm text-black/50">Cargando...</p>
    <p v-else-if="revisiones.length === 0" class="py-6 text-center text-sm text-black/50">
      No hay teléfonos por revisar.
    </p>

    <div
      v-for="r in revisiones"
      v-else
      :key="r.telefono_e164"
      class="rounded-lg border border-gray-200 p-4"
    >
      <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p class="font-semibold text-heading">{{ r.telefono_e164 }}</p>
        <p class="text-xs text-body">Detectado el {{ fecha(r.detectado_en) }}</p>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-left text-sm">
          <thead>
            <tr
              class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
            >
              <th class="py-2 pr-4">Principal</th>
              <th class="py-2 pr-4">Nombre</th>
              <th class="py-2 pr-4">Origen</th>
              <th class="py-2 pr-4">Correo de la app</th>
              <th class="py-2 pr-4">Pedidos</th>
              <th class="py-2 pr-4">Último pedido</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="c in r.clientes"
              :key="c.id_cliente"
              class="border-b border-gray-100 text-heading"
            >
              <td class="py-2 pr-4">
                <input
                  v-model="principal[r.telefono_e164]"
                  type="radio"
                  :name="`principal-${r.telefono_e164}`"
                  :value="c.id_cliente"
                  :aria-label="`Elegir a ${c.nombre} como principal`"
                  class="size-4 accent-accent"
                />
              </td>
              <td class="py-2 pr-4 font-medium">{{ c.nombre }}</td>
              <td class="py-2 pr-4">
                <UiBadge
                  :text="c.origen ? (etiquetaOrigen[c.origen] ?? c.origen) : 'Anterior'"
                  :color="c.origen === 'APP' ? 'purple' : 'blue'"
                />
              </td>
              <td class="py-2 pr-4">
                <label
                  v-if="c.email_acceso && necesitaElegirCorreo(r)"
                  class="flex items-center gap-2"
                >
                  <input
                    v-model="correo[r.telefono_e164]"
                    type="radio"
                    :name="`correo-${r.telefono_e164}`"
                    :value="c.id_cliente"
                    class="size-4 accent-accent"
                  />
                  {{ c.email_acceso }}
                </label>
                <span v-else>{{ c.email_acceso ?? '—' }}</span>
              </td>
              <td class="py-2 pr-4">{{ c.pedidos }}</td>
              <td class="py-2 pr-4">{{ fecha(c.ultimo_pedido_en) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-if="necesitaElegirCorreo(r)" class="mt-2 text-xs text-body">
        Varios de estos clientes entran a la app. Marca qué correo conserva el principal; el otro
        dejará de servir para entrar.
      </p>

      <div class="mt-3 flex justify-end">
        <button
          type="button"
          :disabled="unificando === r.telefono_e164 || principal[r.telefono_e164] === undefined"
          class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-heading disabled:cursor-not-allowed disabled:opacity-60"
          @click="porConfirmar = r"
        >
          {{ unificando === r.telefono_e164 ? 'Unificando...' : 'Unificar' }}
        </button>
      </div>
    </div>

    <UiConfirmDialog
      :open="porConfirmar !== null"
      title="Unificar clientes"
      :message="
        porConfirmar
          ? `Todo el historial pasará a ${nombrePrincipal(porConfirmar)} y los demás clientes de este teléfono quedarán desactivados. Queda registrado en la auditoría. ¿Continuar?`
          : ''
      "
      confirm-label="Unificar"
      @confirm="unificar"
      @cancel="porConfirmar = null"
    />
  </div>
</template>
