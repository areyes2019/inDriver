<script setup lang="ts">
/**
 * Banner de la flotilla, o el de MotoPanda si no ha subido uno (D16, RN-62). El banner por defecto
 * es una ilustración provisional: ciudad, moto y el lema de la imagen de referencia.
 */
defineProps<{ url: string | null; nombre: string }>()
</script>

<template>
  <div class="relative h-44 overflow-hidden">
    <img v-if="url" :src="url" :alt="`Promoción de ${nombre}`" class="size-full object-cover" />
    <template v-else>
      <svg
        class="absolute inset-x-0 bottom-0 h-28 w-full text-morado-500/40"
        viewBox="0 0 400 110"
        preserveAspectRatio="xMidYMax slice"
        aria-hidden="true"
      >
        <path
          fill="currentColor"
          d="M0 110V72h18V52h10v-8h6v8h10v20h14V40l8-14 8 14v32h10V58h22v14h12V30h4l4-12 4 12h4v42h16V60h20v12h10V48h8V34l6-10 6 10v14h8v24h18V56h24v16h12V44l10-18 10 18v28h16V62h16v10h18V50h12v22h20v38z"
        />
      </svg>
      <div class="relative z-10 flex h-full items-center justify-between gap-2 pl-5 pr-2">
        <p class="font-titulo text-[1.7rem] leading-[1.05] font-extrabold text-white italic">
          Tus mandados<br />más rápidos<br /><span class="text-amarillo-500">y seguros</span>
        </p>
        <svg class="moto-avanza h-32 w-36 shrink-0" viewBox="0 0 150 130" aria-hidden="true">
          <circle cx="32" cy="104" r="20" fill="#1b1530" />
          <circle cx="32" cy="104" r="9" fill="#ddd6fe" />
          <circle cx="118" cy="104" r="20" fill="#1b1530" />
          <circle cx="118" cy="104" r="9" fill="#ddd6fe" />
          <path d="M30 100l26-30h40l22 30z" fill="#facc15" />
          <path
            d="M92 70l14-26h10"
            stroke="#1b1530"
            stroke-width="6"
            fill="none"
            stroke-linecap="round"
          />
          <rect
            x="36"
            y="38"
            width="38"
            height="34"
            rx="6"
            fill="#7c3aed"
            stroke="#2e1065"
            stroke-width="3"
          />
          <circle cx="55" cy="55" r="9" fill="#fff" />
          <path d="M74 66l14-30" stroke="#1b1530" stroke-width="12" stroke-linecap="round" />
          <circle cx="92" cy="22" r="15" fill="#1b1530" />
          <path d="M92 14a12 12 0 0 1 12 10H92z" fill="#ddd6fe" />
        </svg>
      </div>
    </template>
  </div>
</template>
