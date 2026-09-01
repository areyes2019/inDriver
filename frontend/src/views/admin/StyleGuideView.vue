<script setup lang="ts">
import { ref } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiToggle from '@/components/ui/UiToggle.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import UiAlert from '@/components/ui/UiAlert.vue'
import UiChip from '@/components/ui/UiChip.vue'
import UiStat from '@/components/ui/UiStat.vue'
import UiBarChart from '@/components/ui/UiBarChart.vue'
import UiStatusBar from '@/components/ui/UiStatusBar.vue'
import UiPersonListItem from '@/components/ui/UiPersonListItem.vue'
import { Icon } from '@iconify/vue'

const swatches = [
  { name: 'heading', hex: '#0f172a', class: 'bg-heading' },
  { name: 'body', hex: '#64748b', class: 'bg-body' },
  { name: 'default', hex: '#e2e8f0', class: 'bg-default border border-default' },
  { name: 'accent', hex: '#6366f1', class: 'bg-accent' },
  { name: 'accent-hover', hex: '#4f46e5', class: 'bg-accent-hover' },
  { name: 'accent-soft', hex: '#eef0ff', class: 'bg-accent-soft border border-default' },
  { name: 'neutral-primary', hex: '#ffffff', class: 'bg-neutral-primary border border-default' },
  { name: 'warning-bg / warning-text', hex: '#fef3c7 / #92400e', class: 'bg-warning-bg' },
  { name: 'info-bg / info-text', hex: '#dbeafe / #1e40af', class: 'bg-info-bg' },
  { name: 'success-bg / success-text', hex: '#d1fae5 / #065f46', class: 'bg-success-bg' },
  { name: 'purple-bg / purple-text', hex: '#f3e8ff / #6d28d9', class: 'bg-purple-bg' },
]

const icons = [
  { name: 'menu', icon: 'flat-color-icons:menu' },
  { name: 'cancel', icon: 'flat-color-icons:cancel' },
  { name: 'settings', icon: 'flat-color-icons:settings' },
  { name: 'search', icon: 'flat-color-icons:search' },
  { name: 'flash-on', icon: 'flat-color-icons:flash-on' },
  { name: 'package', icon: 'flat-color-icons:package' },
  { name: 'automotive', icon: 'flat-color-icons:automotive' },
  { name: 'shipped', icon: 'flat-color-icons:shipped' },
  { name: 'statistics', icon: 'flat-color-icons:statistics' },
  { name: 'positive-dynamic', icon: 'flat-color-icons:positive-dynamic' },
  { name: 'organization', icon: 'flat-color-icons:organization' },
  { name: 'globe', icon: 'flat-color-icons:globe' },
  { name: 'alert-badge (fluent-color)', icon: 'fluent-color:alert-badge-24' },
]

const toggleOn = ref(true)
const toggleOff = ref(false)
const statusBarToggle = ref(true)
const sampleInput = ref('')
const sampleInputWithError = ref('')

const weeklyPower = [
  { label: 'Lun', value: 32 },
  { label: 'Mar', value: 55 },
  { label: 'Mié', value: 41 },
  { label: 'Jue', value: 65 },
  { label: 'Vie', value: 48 },
  { label: 'Sáb', value: 30 },
  { label: 'Dom', value: 38 },
]

const flotilla = [
  { initials: 'R', name: 'Roberto Salas', meta: 'Itálica, Blanca HJT5636', status: 'LIBRE' },
  { initials: 'D', name: 'David Sánchez', meta: 'Itálica HTR-98', status: 'LIBRE' },
  { initials: 'L', name: 'Lila Rodri', meta: 'Honda Civic', status: 'LIBRE' },
]
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-6">
      <div>
        <h1 class="text-2xl font-bold text-heading">Guía de estilo</h1>
        <p class="text-sm text-black/50">
          Paleta, tipografía y componentes base — solo visible en desarrollo.
        </p>
      </div>

      <UiCard title="Colores">
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
          <div v-for="swatch in swatches" :key="swatch.name" class="flex flex-col gap-2">
            <div class="h-16 rounded-xl" :class="swatch.class" />
            <div class="text-sm">
              <p class="font-semibold text-heading">{{ swatch.name }}</p>
              <p class="text-black/50">{{ swatch.hex }}</p>
            </div>
          </div>
        </div>
        <p class="mt-4 text-xs text-body">
          Los badges usan los tokens semánticos de arriba (warning/info/success/purple); solo `red`
          y `gray` siguen usando clases nativas de Tailwind, porque no tienen token propio.
        </p>
      </UiCard>

      <UiCard title="Tipografía">
        <p class="mb-3 text-xs text-body">
          Outfit (<code>--font-heading</code>) para títulos, Inter (<code>--font-sans</code>) para
          el resto del texto.
        </p>
        <div class="flex flex-col gap-3">
          <p class="text-3xl font-bold text-heading">Título grande — 3xl bold</p>
          <p class="text-xl font-semibold text-heading">Título mediano — xl semibold</p>
          <p class="text-base font-medium" style="font-family: var(--font-sans)">
            Texto medio — base medium
          </p>
          <p class="text-sm text-black/70">Texto de párrafo — sm regular</p>
          <p class="text-xs text-black/50">Texto pequeño / auxiliar — xs regular</p>
        </div>
      </UiCard>

      <UiCard title="Iconos (Iconify)">
        <p class="mb-3 text-xs text-body">
          Colección principal <code>flat-color-icons</code>, con <code>fluent-color</code> de
          respaldo para íconos que no existen en la primera (ej. la campana de notificaciones).
        </p>
        <div class="grid grid-cols-3 gap-4 sm:grid-cols-5">
          <div v-for="icon in icons" :key="icon.name" class="flex flex-col items-center gap-2">
            <Icon :icon="icon.icon" width="24" height="24" aria-hidden="true" />
            <span class="text-xs text-body">{{ icon.name }}</span>
          </div>
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

      <UiCard title="Botones (UiButton)">
        <div class="flex flex-wrap items-center gap-4">
          <UiButton :full-width="false">Primario</UiButton>
          <UiButton variant="secondary" :full-width="false">Secundario</UiButton>
          <UiButton disabled :full-width="false">Deshabilitado</UiButton>
        </div>
      </UiCard>

      <UiCard title="Campos de texto (UiInput)">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <UiInput
            v-model="sampleInput"
            label="Correo electrónico"
            type="email"
            placeholder="admin@indriver.com"
          />
          <UiInput
            v-model="sampleInputWithError"
            label="Contraseña"
            type="password"
            error="Este campo es obligatorio."
          />
        </div>
      </UiCard>

      <UiCard title="Avisos (UiAlert)">
        <div class="flex flex-col gap-3">
          <UiAlert variant="info">Aviso informativo.</UiAlert>
          <UiAlert variant="success">Operación completada con éxito.</UiAlert>
          <UiAlert variant="error">Ocurrió un error, intenta de nuevo.</UiAlert>
          <UiAlert variant="warning">Revisa este dato antes de continuar.</UiAlert>
        </div>
      </UiCard>

      <UiCard title="Badges">
        <div class="flex flex-wrap items-center gap-3">
          <UiBadge text="274" color="green" />
          <UiBadge text="1 en cola" color="orange" />
          <UiBadge text="3 conductores" color="blue" />
          <UiBadge text="5 programados" color="purple" />
          <UiBadge text="EN VIVO" color="red" />
          <UiBadge text="Beta" color="gray" />
        </div>
      </UiCard>

      <UiCard title="Chip (UiChip)">
        <div class="flex flex-wrap items-center gap-3">
          <UiChip text="12 en cola" />
        </div>
      </UiCard>

      <UiCard title="Stat (UiStat)">
        <div class="flex flex-wrap gap-4">
          <UiStat icon="flat-color-icons:automotive" label="Mis conductores" value="64" />
          <UiStat
            icon="flat-color-icons:package"
            icon-bg="bg-warning-bg"
            label="Entregas activas"
            value="12"
          />
          <UiStat
            icon="flat-color-icons:positive-dynamic"
            icon-bg="bg-success-bg"
            label="Mi saldo"
            value="$842.50"
          />
        </div>
      </UiCard>

      <UiCard
        title="Tarjeta con encabezado oscuro"
        variant="dark-header"
        subtitle="Centro de monitoreo operativo"
      >
        <template #icon>
          <Icon icon="flat-color-icons:flash-on" width="20" height="20" aria-hidden="true" />
        </template>
        <template #header-end>
          <UiBadge text="EN VIVO" color="red" />
        </template>
        <p class="text-sm text-body">
          Ejemplo de estilo — cuerpo de la tarjeta con contenido de negocio (fuera de alcance de
          esta guía).
        </p>
      </UiCard>

      <UiCard title="Barra de estado (UiStatusBar)">
        <div class="overflow-hidden rounded-xl border border-default">
          <UiStatusBar
            v-model="statusBarToggle"
            label="Panel operativo: Adrián Marino"
            :badges="[
              { text: '1 en cola', color: 'orange' },
              { text: '3 conductores', color: 'blue' },
              { text: 'Saldo $1370.00', color: 'green' },
            ]"
          />
        </div>
      </UiCard>

      <UiCard title="Lista de personas (UiPersonListItem)">
        <div class="divide-y divide-default">
          <UiPersonListItem
            v-for="persona in flotilla"
            :key="persona.name"
            :initials="persona.initials"
            :name="persona.name"
            :meta="persona.meta"
            :status="persona.status"
            status-color="green"
          />
        </div>
      </UiCard>

      <UiCard title="Ejemplo de composición en columnas">
        <p class="mb-4 text-xs text-body">
          Solo un ejemplo de cómo combinar UiCard/UiPersonListItem en varias columnas — no es un
          layout obligatorio de AdminLayout/TenantLayout.
        </p>
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <UiCard title="Pendientes">
            <p class="text-sm text-body">No hay viajes pendientes.</p>
          </UiCard>
          <UiCard title="Panel de Viajes Activos">
            <p class="text-sm text-body">Sin resultados.</p>
          </UiCard>
          <UiCard title="Flotilla">
            <div class="divide-y divide-default">
              <UiPersonListItem
                v-for="persona in flotilla"
                :key="persona.name"
                :initials="persona.initials"
                :name="persona.name"
                :status="persona.status"
                status-color="green"
              />
            </div>
          </UiCard>
        </div>
      </UiCard>

      <UiCard title="Gráfica de barras (datos de ejemplo)">
        <UiBarChart :data="weeklyPower" unit=" kWh" />
      </UiCard>
    </div>
  </AdminLayout>
</template>
