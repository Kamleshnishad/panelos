<template>
  <span class="sort-icon" :class="{ active }" aria-hidden="true">
    <svg width="9" height="12" viewBox="0 0 9 12">
      <path d="M4.5 0.5 L8 4.5 L1 4.5 Z" :fill="active && order === 'asc' ? 'currentColor' : 'var(--border-2)'" />
      <path d="M4.5 11.5 L8 7.5 L1 7.5 Z" :fill="active && order === 'desc' ? 'currentColor' : 'var(--border-2)'" />
    </svg>
  </span>
</template>

<script setup>
import { computed } from 'vue'
const props = defineProps({
  field:   { type: String, required: true },
  current: { type: String, default: '' },
  order:   { type: String, default: 'desc' },
})
const active = computed(() => props.current === props.field)
</script>

<style scoped>
.sort-icon { display: inline-flex; vertical-align: middle; margin-left: 5px; line-height: 0; }
</style>
