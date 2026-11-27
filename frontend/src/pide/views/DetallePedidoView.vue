<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import type { Pedido } from '../api/tipos'
import BotonPrincipal from '../components/BotonPrincipal.vue'
import EncabezadoPantalla from '../components/EncabezadoPantalla.vue'
import { grupoDe, mensajeDe } from '../lib/estados'
import { fechaCorta, km, pesos, tituloModalidad } from '../lib/formato'
import { formatoTelefono } from '../lib/telefono'
import { useAsistenteStore } from '../stores/asistente'
import { useMarcaStore } from '../stores/marca'

const route = useRoute()
const router = useRouter()
const marca = useMarcaStore()
const asistente = useAsistenteStore()
const pedido = ref<Pedido | null>(null)

onMounted(async () => {
  const p = await api.pedido(Number(route.params.id))
  if (!p) {
    router.replace({ name: 'pedidos', params: { slug: marca.slug } })
    return
  }
  if (grupoDe(p.estado) === 'activos') {
    router.replace({ name: 'pedido', params: { slug: marca.slug, id: p.id } })
    return
  }
  pedido.value = p
})

function repetir(): void {
  if (!pedido.value) return
  asistente.repetir(pedido.value)
  router.push({
    name: 'pedir',
    params: { slug: marca.slug, tipo: pedido.value.tipo === 'ENVIAR' ? 'enviar' : 'recibir' },
    query: { repetir: '1' },
  })
}
</script>

<template>
  <main class="pb-10">
    <EncabezadoPantalla :titulo="pedido?.numero ?? 'Pedido'" :volver-a="`/${marca.slug}/pedidos`" />

    <div v-if="pedido" class="space-y-4 px-5">
      <div class="rounded-3xl bg-white p-4 ring-1 ring-linea">
        <p class="text-sm text-suave">{{ fechaCorta(pedido.programadoPara ?? pedido.creadoEn) }}</p>
        <p
          class="mt-1 font-titulo text-2xl font-bold"
          :class="pedido.estado === 'CANCELADO' ? 'text-peligro' : 'text-exito'"
        >
          {{ mensajeDe(pedido.estado, pedido.sinRepartidor) }}
        </p>
        <p v-if="pedido.repartidor" class="mt-1 flex items-center gap-1.5 text-sm text-suave">
          <Icon icon="tabler:motorbike" class="size-4" /> {{ pedido.repartidor.nombre }}
        </p>
      </div>

      <ol class="space-y-4 rounded-3xl bg-white p-4 ring-1 ring-linea">
        <li class="flex gap-3">
          <span class="mt-1 size-3 shrink-0 rounded-full bg-exito" />
          <div class="min-w-0 text-sm">
            <p class="text-xs font-semibold tracking-wide text-suave uppercase">Recogida</p>
            <p>{{ pedido.recogida.direccion }}</p>
            <p v-if="pedido.referenciasRecogida" class="text-suave">
              {{ pedido.referenciasRecogida }}
            </p>
            <p class="text-suave">
              {{ pedido.contactoRecogida.nombre }} ·
              {{ formatoTelefono(pedido.contactoRecogida.telefono) }}
            </p>
          </div>
        </li>
        <li class="flex gap-3">
          <span class="mt-1 size-3 shrink-0 rounded-full bg-peligro" />
          <div class="min-w-0 text-sm">
            <p class="text-xs font-semibold tracking-wide text-suave uppercase">Entrega</p>
            <p>{{ pedido.entrega.direccion }}</p>
            <p v-if="pedido.referenciasEntrega" class="text-suave">
              {{ pedido.referenciasEntrega }}
            </p>
            <p class="text-suave">
              {{ pedido.recibe.nombre }} · {{ formatoTelefono(pedido.recibe.telefono) }}
            </p>
          </div>
        </li>
      </ol>

      <dl class="space-y-2 rounded-3xl bg-white p-4 text-sm ring-1 ring-linea">
        <div class="flex justify-between">
          <dt class="text-suave">Distancia</dt>
          <dd>{{ km(pedido.km) }}</dd>
        </div>
        <div class="flex justify-between">
          <dt class="text-suave">Envío</dt>
          <dd>{{ pesos(pedido.importeEnvio) }}</dd>
        </div>
        <div v-if="pedido.importeProducto" class="flex justify-between">
          <dt class="text-suave">Producto</dt>
          <dd>{{ pesos(pedido.importeProducto) }}</dd>
        </div>
        <div class="flex justify-between border-t border-linea pt-2 font-semibold">
          <dt>Total</dt>
          <dd>{{ pesos(pedido.importeEnvio + pedido.importeProducto) }}</dd>
        </div>
        <p class="text-suave">Efectivo · {{ tituloModalidad(pedido.modalidad) }}</p>
      </dl>

      <BotonPrincipal icono="tabler:repeat" @click="repetir">Repetir este pedido</BotonPrincipal>
    </div>
  </main>
</template>
