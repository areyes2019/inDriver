<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/lib/http'
import TenantLayout from '@/layouts/TenantLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'

interface PagoConductor {
  id_venta: number
  id_conductor: number
  fecha_venta: string
  monto_pagado: string
  cantidad_viajes: number
  conductor: {
    usuario: {
      nombre: string
      apellido_paterno: string
    } | null
  } | null
}

const route = useRoute()
const slug = computed(() => route.params.slug as string)

const pagos = ref<PagoConductor[]>([])
const totalGeneral = ref(0)
const loading = ref(false)
const error = ref('')

function nombreConductor(pago: PagoConductor) {
  const usuario = pago.conductor?.usuario
  return usuario
    ? `${usuario.nombre} ${usuario.apellido_paterno}`
    : `Conductor #${pago.id_conductor}`
}

async function fetchReporte() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await http.get(`/t/${slug.value}/reportes/pagos-conductores`)
    pagos.value = data.data
    totalGeneral.value = data.total_general
  } catch {
    error.value = 'No se pudo cargar el reporte de pagos.'
  } finally {
    loading.value = false
  }
}

onMounted(fetchReporte)
</script>

<template>
  <TenantLayout>
    <UiCard title="Reporte de pagos de conductores">
      <p v-if="error" role="alert" class="mb-4 text-sm text-red-600">{{ error }}</p>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[560px] text-left text-sm">
          <thead>
            <tr
              class="border-b border-gray-200 text-xs font-semibold tracking-wide text-black/50 uppercase"
            >
              <th class="py-2 pr-4">Conductor</th>
              <th class="py-2 pr-4">Fecha</th>
              <th class="py-2 pr-4">Monto</th>
              <th class="py-2 pr-4">Viajes</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="4" class="py-6 text-center text-black/50">Cargando...</td>
            </tr>
            <tr v-else-if="pagos.length === 0">
              <td colspan="4" class="py-6 text-center text-black/50">No hay pagos registrados.</td>
            </tr>
            <tr
              v-for="pago in pagos"
              v-else
              :key="pago.id_venta"
              class="border-b border-gray-100 text-heading"
            >
              <td class="py-2 pr-4 font-medium">{{ nombreConductor(pago) }}</td>
              <td class="py-2 pr-4">{{ new Date(pago.fecha_venta).toLocaleDateString() }}</td>
              <td class="py-2 pr-4">${{ pago.monto_pagado }}</td>
              <td class="py-2 pr-4">{{ pago.cantidad_viajes }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="mt-4 text-sm font-semibold text-heading">
        Total general pagado: ${{ totalGeneral }}
      </p>
    </UiCard>
  </TenantLayout>
</template>
