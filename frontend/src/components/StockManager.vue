<template>
  <div class="sm-wrap">
    <!-- Tab navigation -->
    <div class="stock-tabs">
      <button :class="['stock-tab', { active: tab === 'coils' }]"     @click="tab = 'coils'">Coil Stock</button>
      <button :class="['stock-tab', { active: tab === 'chemicals' }]" @click="tab = 'chemicals'">Chemical Stock</button>
      <button :class="['stock-tab', { active: tab === 'log' }]"       @click="tab = 'log'">Transaction Log</button>
      <button :class="['stock-tab', { active: tab === 'alerts' }]"    @click="tab = 'alerts'">
        Alerts
        <span class="alert-dot" v-if="alertCount > 0">{{ alertCount }}</span>
      </button>
    </div>

    <coil-stock      v-if="tab === 'coils'" />
    <chemical-stock  v-else-if="tab === 'chemicals'" />
    <transaction-log v-else-if="tab === 'log'" />

    <!-- Alerts -->
    <div v-else-if="tab === 'alerts'" class="alerts-panel">
      <div class="alerts-toolbar">
        <h3>Stock Alerts</h3>
        <button class="btn btn-ghost btn-sm" @click="loadAlerts">↻ Refresh</button>
      </div>
      <div v-if="loadingAlerts" class="loading-hint">Loading alerts…</div>
      <div v-else-if="alerts.length === 0" class="empty-hint">No active alerts — all stock levels are healthy.</div>
      <div v-else class="alert-list">
        <div v-for="a in alerts" :key="a.id" class="alert-card" :class="a.alert_type">
          <div class="alert-card-header">
            <span class="alert-icon">{{ a.alert_type === 'low_stock' ? '⚠' : '⏰' }}</span>
            <span class="alert-title">{{ alertTitle(a) }}</span>
            <span :class="['alert-status', a.status]">{{ a.status }}</span>
          </div>
          <div class="alert-body">
            <span>Current: <strong>{{ fmtQty(a.current_quantity) }}</strong></span>
            <span>Reorder: {{ fmtQty(a.reorder_level) }}</span>
            <span>Type: {{ a.item_type }}</span>
          </div>
          <button
            v-if="a.status === 'active'"
            class="btn btn-resolve btn-sm"
            :disabled="resolvingId === a.id"
            @click="resolveAlert(a)"
          >{{ resolvingId === a.id ? 'Resolving…' : '✓ Resolve' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import CoilStock      from './CoilStock.vue'
import ChemicalStock  from './ChemicalStock.vue'
import TransactionLog from './TransactionLog.vue'
import stockService   from '../services/stockService.js'

const tab          = ref('coils')
const alerts       = ref([])
const alertCount   = ref(0)
const loadingAlerts = ref(false)
const resolvingId  = ref(null)

async function loadAlerts() {
  loadingAlerts.value = true
  try {
    const res     = await stockService.getAlerts({ status: 'active' })
    alerts.value  = res?.data?.data ?? res?.data ?? []
    alertCount.value = alerts.value.filter(a => a.status === 'active').length
  } catch { alerts.value = [] }
  finally { loadingAlerts.value = false }
}

async function resolveAlert(alert) {
  resolvingId.value = alert.id
  try {
    await stockService.resolveAlert(alert.id)
    alerts.value = alerts.value.filter(a => a.id !== alert.id)
    alertCount.value = Math.max(0, alertCount.value - 1)
  } catch { /* silent */ }
  finally { resolvingId.value = null }
}

function alertTitle(a) {
  return a.alert_type === 'low_stock' ? 'Low Stock' : 'Expiring Soon'
}

function fmtQty(n) { return Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }) }

onMounted(loadAlerts)
</script>

<style scoped>
.sm-wrap { padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; }

.stock-tabs { display: flex; gap: 4px; margin-bottom: 22px; border-bottom: 2px solid #e0e0e0; padding-bottom: 0; }
.stock-tab  { padding: 9px 20px; border: none; background: none; font-size: 14px; font-weight: 600; color: #888; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.15s; }
.stock-tab.active { color: var(--primary); border-bottom-color: var(--primary); }
.stock-tab:hover:not(.active) { color: #333; }
.alert-dot { display: inline-flex; align-items: center; justify-content: center; background: #c62828; color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; font-weight: 700; margin-left: 6px; }

/* Alerts */
.alerts-panel   { }
.alerts-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.alerts-toolbar h3 { margin: 0; font-size: 16px; color: var(--primary); font-weight: 700; }
.loading-hint   { color: #aaa; font-size: 13px; padding: 16px; text-align: center; }
.empty-hint     { text-align: center; color: #aaa; font-style: italic; padding: 30px; border: 2px dashed #e0e0e0; border-radius: 8px; }

.alert-list { display: flex; flex-direction: column; gap: 10px; }
.alert-card { background: white; border: 1px solid #e0e0e0; border-radius: 10px; padding: 14px 18px; }
.alert-card.low_stock    { border-left: 4px solid #ffb74d; }
.alert-card.expiring_soon { border-left: 4px solid #ef5350; }

.alert-card-header { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.alert-icon  { font-size: 18px; }
.alert-title { font-size: 14px; font-weight: 700; color: #333; flex: 1; }
.alert-status { font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 2px 8px; border-radius: 8px; }
.alert-status.active   { background: #ffebee; color: #c62828; }
.alert-status.resolved { background: #e8f5e9; color: #2e7d32; }

.alert-body { display: flex; gap: 16px; font-size: 12px; color: #666; margin-bottom: 10px; flex-wrap: wrap; }
.alert-body strong { color: #c62828; }

.btn        { padding: 6px 14px; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; }
.btn-ghost  { background: transparent; border: 1px solid #ddd; color: #555; }
.btn-resolve { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
.btn-resolve:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-sm     { padding: 5px 12px; font-size: 12px; }
</style>
