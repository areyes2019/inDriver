<script setup lang="ts">
/** Botón principal de Inicio (6.2): Enviar o Recibir, con la caja dibujada de la referencia. */
defineProps<{ tipo: 'enviar' | 'recibir' }>()
</script>

<template>
  <button
    type="button"
    class="flex aspect-[1/1.05] w-full flex-col items-center justify-center gap-3 rounded-[1.75rem] bg-white shadow-tarjeta ring-1 ring-linea/70 transition-transform active:scale-[0.97]"
  >
    <svg v-if="tipo === 'enviar'" viewBox="0 0 120 90" class="h-20 w-28" aria-hidden="true">
      <rect x="4" y="30" width="26" height="9" rx="4.5" fill="#facc15" />
      <rect x="0" y="46" width="30" height="9" rx="4.5" fill="#facc15" />
      <rect x="10" y="62" width="20" height="9" rx="4.5" fill="#facc15" />
      <path
        d="M44 28l34-16 34 16v40l-34 16-34-16z"
        fill="#ede9fe"
        stroke="#4c1d95"
        stroke-width="5"
        stroke-linejoin="round"
      />
      <path
        d="M44 28l34 16 34-16M78 44v40"
        fill="none"
        stroke="#4c1d95"
        stroke-width="5"
        stroke-linejoin="round"
      />
      <path
        d="M60 20l34 16v12"
        fill="none"
        stroke="#c4b5fd"
        stroke-width="5"
        stroke-linejoin="round"
      />
    </svg>
    <svg v-else viewBox="0 0 120 96" class="h-20 w-28" aria-hidden="true">
      <path
        d="M14 46l-10-6M14 58H2M106 46l10-6M106 58h12"
        stroke="#c4b5fd"
        stroke-width="5"
        stroke-linecap="round"
      />
      <path
        d="M26 46l34-14 34 14v34l-34 14-34-14z"
        fill="#ede9fe"
        stroke="#4c1d95"
        stroke-width="5"
        stroke-linejoin="round"
      />
      <path
        d="M26 46l34 14 34-14M60 60v34"
        fill="none"
        stroke="#4c1d95"
        stroke-width="5"
        stroke-linejoin="round"
      />
      <path
        d="M52 4h16v22h12L60 46 40 26h12z"
        fill="#facc15"
        stroke="#4c1d95"
        stroke-width="4"
        stroke-linejoin="round"
      />
    </svg>
    <span class="font-titulo text-[1.75rem] font-extrabold text-tinta">
      {{ tipo === 'enviar' ? 'Enviar' : 'Recibir' }}
    </span>
  </button>
</template>
