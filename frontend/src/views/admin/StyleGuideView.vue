<script setup lang="ts">
import { ref } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiToggle from '@/components/ui/UiToggle.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiBarChart from '@/components/ui/UiBarChart.vue'

const swatches = [
  { name: 'brand-dark', hex: '#000814', class: 'bg-brand-dark' },
  { name: 'brand-yellow', hex: '#ffc300', class: 'bg-brand-yellow' },
  { name: 'brand-blue', hex: '#003566', class: 'bg-brand-blue' },
]

const toggleOn = ref(true)
const toggleOff = ref(false)

const weeklyPower = [
  { label: 'Lun', value: 32 },
  { label: 'Mar', value: 55 },
  { label: 'Mié', value: 41 },
  { label: 'Jue', value: 65 },
  { label: 'Vie', value: 48 },
  { label: 'Sáb', value: 30 },
  { label: 'Dom', value: 38 },
]
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-6">
      <div>
        <h1 class="text-2xl font-bold text-brand-dark">Guía de estilo</h1>
        <p class="text-sm text-black/50">
          Paleta, tipografía y componentes base — solo visible en desarrollo.
        </p>
      </div>

      <UiCard title="Colores">
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
          <div v-for="swatch in swatches" :key="swatch.name" class="flex flex-col gap-2">
            <div class="h-16 rounded-xl" :class="swatch.class" />
            <div class="text-sm">
              <p class="font-semibold text-brand-dark">{{ swatch.name }}</p>
              <p class="text-black/50">{{ swatch.hex }}</p>
            </div>
          </div>
        </div>
      </UiCard>

      <UiCard title="Tipografía (Montserrat)">
        <div class="flex flex-col gap-3">
          <p class="text-3xl font-bold text-brand-dark">Título grande — 3xl bold</p>
          <p class="text-xl font-semibold text-brand-dark">Título mediano — xl semibold</p>
          <p class="text-base font-medium text-brand-dark">Texto medio — base medium</p>
          <p class="text-sm text-black/70">Texto de párrafo — sm regular</p>
          <p class="text-xs text-black/50">Texto pequeño / auxiliar — xs regular</p>
        </div>
      </UiCard>

      <UiCard title="Toggles">
        <div class="flex flex-wrap items-center gap-6">
          <div class="flex items-center gap-3">
            <UiToggle v-model="toggleOn" />
            <span class="text-sm text-black/70">Activo</span>
          </div>
          <div class="flex items-center gap-3">
            <UiToggle v-model="toggleOff" />
            <span class="text-sm text-black/70">Inactivo</span>
          </div>
          <div class="flex items-center gap-3">
            <UiToggle :model-value="true" disabled />
            <span class="text-sm text-black/70">Deshabilitado</span>
          </div>
        </div>
      </UiCard>

      <UiCard title="Badges">
        <div class="flex items-center gap-3">
          <UiBadge text="2" color="green" />
          <UiBadge text="Nuevo" color="yellow" />
          <UiBadge text="Beta" color="blue" />
        </div>
      </UiCard>

      <UiCard title="Gráfica de barras (AI power analytics, datos de ejemplo)">
        <UiBarChart :data="weeklyPower" unit=" kWh" />
      </UiCard>
    </div>
  </AdminLayout>
</template>
