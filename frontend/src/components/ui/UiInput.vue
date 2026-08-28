<script setup lang="ts">
withDefaults(
  defineProps<{
    label: string
    type?: 'text' | 'email' | 'password'
    placeholder?: string
    autocomplete?: string
    required?: boolean
    minlength?: number
    error?: string
  }>(),
  {
    type: 'text',
    placeholder: undefined,
    autocomplete: undefined,
    required: false,
    minlength: undefined,
    error: undefined,
  },
)

const model = defineModel<string>({ required: true })
</script>

<template>
  <label class="block">
    <span class="mb-1 block text-sm font-medium text-heading">{{ label }}</span>
    <input
      v-model="model"
      :type="type"
      :required="required"
      :autocomplete="autocomplete"
      :minlength="minlength"
      :placeholder="placeholder"
      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-heading placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
    />
    <p v-if="error" role="alert" class="mt-1 text-sm text-red-600">{{ error }}</p>
  </label>
</template>
