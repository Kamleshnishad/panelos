<template>
  <div class="bi-card">
    <div class="bi-head">
      <div>
        <h3>{{ title }}</h3>
        <div v-if="subtitle" class="bi-sub">{{ subtitle }}</div>
      </div>
      <button v-if="!empty" class="bi-toggle" @click="showTable = !showTable">{{ showTable ? 'Chart' : 'Table' }}</button>
    </div>

    <div v-if="empty" class="bi-empty">No data yet.</div>

    <!-- Table view (accessibility / exact numbers) -->
    <table v-else-if="showTable" class="bi-table">
      <thead><tr><th></th><th v-for="s in series" :key="s.name">{{ s.name }}</th></tr></thead>
      <tbody>
        <tr v-for="(l, i) in labels" :key="l"><td>{{ l }}</td><td v-for="s in series" :key="s.name">{{ fmt(s.data[i]) }}</td></tr>
      </tbody>
    </table>

    <div v-else class="bi-canvas" :style="{ height: boxHeight + 'px' }"><canvas ref="canvas" :aria-label="title" role="img"></canvas></div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount, nextTick } from 'vue'
import { Chart, registerables } from 'chart.js'

Chart.register(...registerables)
Chart.defaults.font.family = 'inherit'
Chart.defaults.font.size = 11
Chart.defaults.color = '#6b7280'

// Validated categorical palette (dataviz reference, light mode) — fixed order, never cycled.
const PALETTE = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948']

const props = defineProps({
  title:    { type: String, required: true },
  subtitle: String,
  type:     { type: String, default: 'line' }, // line | bar | hbar | donut
  labels:   { type: Array, default: () => [] },
  series:   { type: Array, default: () => [] }, // [{ name, data: [] }]
  height:   { type: Number, default: 240 },
  fmt:      { type: Function, default: v => Number(v || 0).toLocaleString('en-IN') },
})

const canvas = ref(null)
const showTable = ref(false)
let chart = null

const empty = computed(() => !props.labels.length || props.series.every(s => s.data.every(v => !Number(v))))
// Ranked bars grow with row count so labels never squash
const boxHeight = computed(() => props.type === 'hbar' ? Math.max(120, props.labels.length * 30 + 20) : props.height)

function config() {
  const { type, labels, series, fmt } = props
  if (type === 'donut') {
    const total = series[0].data.reduce((a, v) => a + Number(v || 0), 0)
    return {
      type: 'doughnut',
      data: { labels, datasets: [{ label: series[0].name, data: series[0].data, backgroundColor: PALETTE, borderColor: '#fff', borderWidth: 2 }] },
      options: {
        cutout: '62%', maintainAspectRatio: false,
        plugins: {
          legend: { position: 'right', labels: { boxWidth: 10, boxHeight: 10, padding: 10 } },
          tooltip: { callbacks: { label: c => ` ${fmt(c.parsed)} (${total ? Math.round(c.parsed / total * 100) : 0}%)` } },
        },
      },
    }
  }

  const horizontal = type === 'hbar'
  const isLine = type === 'line'
  const valueAxis = { beginAtZero: true, grid: { color: '#eef0f4' }, border: { display: false }, ticks: { callback: v => fmt(v), maxTicksLimit: 5 } }
  const catAxis = { grid: { display: false }, border: { display: false } }
  const tooltip = { callbacks: { label: c => ` ${c.dataset.label}: ${fmt(horizontal ? c.parsed.x : c.parsed.y)}` } }

  return {
    type: isLine ? 'line' : 'bar',
    data: {
      labels,
      datasets: series.map((s, i) => ({
        label: s.name, data: s.data,
        borderColor: PALETTE[i], backgroundColor: PALETTE[i],
        borderWidth: 2, pointRadius: 0, pointHoverRadius: 5, tension: 0.25,
        borderRadius: 4, borderSkipped: 'start', maxBarThickness: horizontal ? 16 : 28,
      })),
    },
    options: {
      maintainAspectRatio: false,
      indexAxis: horizontal ? 'y' : 'x',
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: series.length > 1, align: 'start', labels: { boxWidth: 10, boxHeight: 10 } },
        tooltip,
      },
      scales: horizontal ? { x: valueAxis, y: catAxis } : { x: catAxis, y: valueAxis },
    },
  }
}

async function render() {
  chart?.destroy(); chart = null
  await nextTick()
  if (canvas.value) chart = new Chart(canvas.value, config())
}

watch(() => [props.labels, props.series, showTable.value], render, { deep: true, immediate: true })
onBeforeUnmount(() => chart?.destroy())
</script>

<style scoped>
.bi-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 18px 20px; box-shadow: var(--shadow-xs); min-width: 0; }
.bi-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 12px; }
.bi-head h3 { margin: 0; font-size: 14px; font-weight: 700; color: var(--text); }
.bi-sub { font-size: 11.5px; color: var(--text-3); margin-top: 2px; }
.bi-toggle { background: none; border: 1px solid var(--border); border-radius: 6px; font-size: 11px; color: var(--text-2); padding: 3px 9px; cursor: pointer; }
.bi-toggle:hover { border-color: var(--primary-bd); color: var(--primary); }
.bi-empty { color: var(--text-3); font-style: italic; font-size: 13px; padding: 40px 0; text-align: center; }
.bi-canvas { position: relative; }
.bi-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.bi-table th, .bi-table td { padding: 5px 8px; border-bottom: 1px solid var(--border); text-align: right; text-transform: capitalize; }
.bi-table th:first-child, .bi-table td:first-child { text-align: left; color: var(--text-2); }
</style>
