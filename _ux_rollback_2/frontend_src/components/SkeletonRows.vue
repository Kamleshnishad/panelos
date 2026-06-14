<template>
  <tr v-for="r in rows" :key="'sk' + r" class="skeleton-row">
    <td v-for="c in cols" :key="c"><span class="skeleton" :style="{ width: widths[(c - 1) % widths.length] }"></span></td>
  </tr>
</template>

<script setup>
defineProps({
  rows:   { type: Number, default: 6 },
  cols:   { type: Number, default: 6 },
  widths: { type: Array, default: () => ['70%', '45%', '60%', '40%', '55%', '50%', '35%', '60%', '45%'] },
})
</script>

<style scoped>
.skeleton-row td { padding: 11px 12px; border-bottom: 1px solid var(--row); }
.skeleton { display: block; height: 12px; border-radius: 4px; }
</style>
