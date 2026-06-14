<template>
  <div class="ml-dashboard">
    <div class="dashboard-header">
      <h2>🤖 ML Demand Forecasting</h2>
      <div class="header-controls">
        <select v-model="selectedPanelType" class="form-input small">
          <option value="">Select Panel Type</option>
          <option v-for="pt in panelTypes" :key="pt.id" :value="pt.id">
            {{ pt.type }}
          </option>
        </select>
        <select v-model="horizonDays" class="form-input small">
          <option value="7">7 days</option>
          <option value="14">14 days</option>
          <option value="30">30 days</option>
          <option value="60">60 days</option>
          <option value="90">90 days</option>
        </select>
        <button @click="runForecast" :disabled="loading || !selectedPanelType" class="btn-primary">
          {{ loading ? 'Running...' : '▶ Run ML Forecast' }}
        </button>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tab-bar">
      <button v-for="tab in tabs" :key="tab.id"
        @click="activeTab = tab.id"
        :class="['tab-btn', { active: activeTab === tab.id }]"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- Forecast Results -->
    <div v-if="activeTab === 'forecast' && forecast" class="tab-content">
      <div class="kpi-grid">
        <div class="kpi-card">
          <div class="kpi-label">Total Demand</div>
          <div class="kpi-value">{{ forecast.total_predicted_demand }}</div>
          <div class="kpi-sub">units in {{ forecast.horizon_days }} days</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label">Daily Average</div>
          <div class="kpi-value">{{ forecast.average_daily_demand }}</div>
          <div class="kpi-sub">units/day</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label">Confidence</div>
          <div class="kpi-value" :style="{ color: confidenceColor(forecast.confidence_score) }">
            {{ forecast.confidence_score }}%
          </div>
          <div class="kpi-sub">model confidence</div>
        </div>
        <div class="kpi-card" :class="['risk-' + forecast.risk_level]">
          <div class="kpi-label">Risk Level</div>
          <div class="kpi-value">{{ forecast.risk_level.toUpperCase() }}</div>
          <div class="kpi-sub">current: {{ forecast.current_stock }} units</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label">Reorder Qty</div>
          <div class="kpi-value">{{ forecast.reorder_quantity }}</div>
          <div class="kpi-sub">by {{ forecast.recommended_order_date }}</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label">Anomalies</div>
          <div class="kpi-value" :style="{ color: forecast.anomalies_detected > 0 ? '#f44336' : '#4caf50' }">
            {{ forecast.anomalies_detected }}
          </div>
          <div class="kpi-sub">detected in history</div>
        </div>
      </div>

      <!-- Trend & Seasonality -->
      <div class="insights-row">
        <div class="insight-card">
          <h4>📈 Trend Analysis</h4>
          <div class="trend-indicator" :class="forecast.trend?.direction">
            <span class="trend-icon">{{ trendIcon(forecast.trend?.direction) }}</span>
            <span class="trend-label">{{ formatTrend(forecast.trend?.direction) }}</span>
          </div>
          <div class="trend-detail">
            Slope: {{ forecast.trend?.slope }} | Change: {{ forecast.trend?.pct_change }}%/day
          </div>
        </div>

        <div class="insight-card">
          <h4>🔄 Seasonality</h4>
          <div v-if="forecast.seasonality?.detected" class="seasonality-detected">
            ✅ Detected ({{ forecast.seasonality.period }}-day cycle)
            <div class="strength-bar">
              <div class="strength-fill" :style="{ width: (forecast.seasonality.strength * 100) + '%' }"></div>
            </div>
            Strength: {{ (forecast.seasonality.strength * 100).toFixed(1) }}%
          </div>
          <div v-else class="seasonality-none">
            ➖ No significant seasonality detected
          </div>
        </div>

        <div class="insight-card">
          <h4>⚙️ Model Ensemble</h4>
          <div v-if="forecast.model_breakdown" class="model-bars">
            <div v-for="(val, name) in filteredModels" :key="name" class="model-bar-row">
              <span class="model-name">{{ formatModelName(name) }}</span>
              <div class="bar-bg">
                <div class="bar-fill" :style="{ width: barWidth(val) + '%' }"></div>
              </div>
              <span class="bar-val">{{ val }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Daily predictions chart (ASCII bar chart) -->
      <div class="chart-section">
        <h4>📊 Daily Demand Predictions</h4>
        <div class="mini-chart">
          <div
            v-for="(val, idx) in forecast.daily_predictions"
            :key="idx"
            class="bar-col"
            :title="`Day ${idx + 1}: ${val} units`"
          >
            <div class="bar" :style="{ height: barHeight(val, forecast.daily_predictions) + 'px' }"></div>
            <div class="bar-label" v-if="(idx + 1) % 5 === 0">{{ idx + 1 }}</div>
          </div>
        </div>
        <div class="chart-legend">
          <span>Day →</span>
          <span style="float:right">Max: {{ Math.max(...forecast.daily_predictions) }} units</span>
        </div>
      </div>
    </div>

    <!-- Model Comparison -->
    <div v-if="activeTab === 'compare'" class="tab-content">
      <button @click="runModelComparison" :disabled="loading || !selectedPanelType" class="btn-secondary">
        {{ loading ? 'Comparing...' : '🔬 Compare All Models' }}
      </button>

      <div v-if="comparison" class="comparison-grid">
        <div
          v-for="(metrics, name) in comparison.models"
          :key="name"
          class="model-card"
          :class="{ 'best-model': name === comparison.recommended }"
        >
          <div class="model-header">
            <span class="model-title">{{ formatModelName(name) }}</span>
            <span v-if="name === comparison.recommended" class="best-badge">⭐ Best</span>
          </div>
          <div class="metric-row">
            <span>Accuracy</span>
            <strong :style="{ color: accuracyColor(metrics.accuracy) }">{{ metrics.accuracy }}%</strong>
          </div>
          <div class="metric-row">
            <span>MAE</span>
            <strong>{{ metrics.mae }}</strong>
          </div>
          <div class="metric-row">
            <span>RMSE</span>
            <strong>{{ metrics.rmse }}</strong>
          </div>
          <div class="metric-row">
            <span>MAPE</span>
            <strong>{{ metrics.mape }}%</strong>
          </div>
        </div>
      </div>
    </div>

    <!-- Anomalies -->
    <div v-if="activeTab === 'anomalies'" class="tab-content">
      <button @click="loadAnomalies" :disabled="loading || !selectedPanelType" class="btn-secondary">
        {{ loading ? 'Detecting...' : '🔍 Detect Anomalies' }}
      </button>

      <div v-if="anomalyData" class="anomaly-section">
        <div class="anomaly-summary">
          <div class="anomaly-stat">
            <div class="stat-value">{{ anomalyData.data_points }}</div>
            <div class="stat-label">Data Points</div>
          </div>
          <div class="anomaly-stat">
            <div class="stat-value" :style="{ color: anomalyData.anomalies.length > 0 ? '#f44336' : '#4caf50' }">
              {{ anomalyData.anomalies.length }}
            </div>
            <div class="stat-label">Anomalies</div>
          </div>
          <div class="anomaly-stat">
            <div class="stat-value">{{ anomalyData.confidence }}%</div>
            <div class="stat-label">Confidence</div>
          </div>
        </div>

        <div v-if="anomalyData.anomalies.length > 0" class="anomaly-list">
          <h5>Detected Anomalies</h5>
          <div v-for="(a, idx) in anomalyData.anomalies" :key="idx" class="anomaly-item" :class="a.type">
            <span class="anomaly-icon">{{ a.type === 'spike' ? '📈' : '📉' }}</span>
            <span>Day {{ a.day_index + 1 }}: {{ a.quantity }} units (z={{ a.z_score }})</span>
            <span class="anomaly-type">{{ a.type.toUpperCase() }}</span>
          </div>
        </div>
        <div v-else class="no-anomalies">✅ No anomalies detected in historical data</div>
      </div>
    </div>

    <!-- Performance -->
    <div v-if="activeTab === 'performance'" class="tab-content">
      <button @click="loadPerformance" :disabled="loading" class="btn-secondary">
        {{ loading ? 'Loading...' : '📊 Load Performance' }}
      </button>

      <div v-if="performance" class="performance-section">
        <div class="perf-grid">
          <div class="perf-card">
            <div class="perf-value">{{ performance.total_records }}</div>
            <div class="perf-label">Total Records</div>
          </div>
          <div class="perf-card highlight">
            <div class="perf-value" :style="{ color: accuracyColor(performance.avg_accuracy) }">
              {{ performance.avg_accuracy }}%
            </div>
            <div class="perf-label">Avg Accuracy</div>
          </div>
          <div class="perf-card">
            <div class="perf-value">{{ performance.avg_mape }}%</div>
            <div class="perf-label">Avg MAPE</div>
          </div>
          <div class="perf-card">
            <div class="perf-value" style="color: #4caf50">{{ performance.best_accuracy }}%</div>
            <div class="perf-label">Best Accuracy</div>
          </div>
          <div class="perf-card">
            <div class="perf-value" style="color: #f44336">{{ performance.worst_accuracy }}%</div>
            <div class="perf-label">Worst Accuracy</div>
          </div>
        </div>
      </div>
      <div v-else-if="performanceError" class="no-performance">
        {{ performanceError }}
      </div>
    </div>

    <div v-if="error" class="error-alert">{{ error }}</div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '@/services/api'

const panelTypes = ref([])
const selectedPanelType = ref('')
const horizonDays = ref('30')
const activeTab = ref('forecast')
const loading = ref(false)
const forecast = ref(null)
const comparison = ref(null)
const anomalyData = ref(null)
const performance = ref(null)
const performanceError = ref(null)
const error = ref(null)

const tabs = [
  { id: 'forecast',    label: '🔮 Forecast' },
  { id: 'compare',     label: '🔬 Compare Models' },
  { id: 'anomalies',   label: '⚠️ Anomalies' },
  { id: 'performance', label: '📊 Accuracy' },
]

const filteredModels = computed(() => {
  if (!forecast.value?.model_breakdown) return {}
  const { ensemble, ...rest } = forecast.value.model_breakdown
  return rest
})

const formatModelName = (name) => {
  const map = {
    linear_regression:      'Linear Regression',
    exponential_smoothing:  'Exp. Smoothing',
    moving_average:         'Moving Average',
    seasonal_decomposition: 'Seasonal',
  }
  return map[name] || name
}

const formatTrend = (dir) => {
  const map = {
    strong_up:   'Strong Upward',
    up:          'Upward',
    stable:      'Stable',
    down:        'Downward',
    strong_down: 'Strong Downward',
  }
  return map[dir] || dir
}

const trendIcon = (dir) => {
  const map = {
    strong_up: '⬆️⬆️', up: '⬆️', stable: '➡️', down: '⬇️', strong_down: '⬇️⬇️'
  }
  return map[dir] || '➡️'
}

const confidenceColor = (score) => {
  if (score >= 80) return '#4caf50'
  if (score >= 60) return '#ff9800'
  return '#f44336'
}

const accuracyColor = (pct) => {
  if (pct >= 80) return '#4caf50'
  if (pct >= 60) return '#ff9800'
  return '#f44336'
}

const barWidth = (val) => {
  const max = Math.max(...Object.values(forecast.value?.model_breakdown || {})) || 1
  return Math.round((val / max) * 100)
}

const barHeight = (val, arr) => {
  const max = Math.max(...arr) || 1
  return Math.round((val / max) * 80) + 4
}

const runForecast = async () => {
  loading.value = true
  error.value = null

  try {
    const res = await api.post('/forecasts/ml', {
      panel_type_id: selectedPanelType.value,
      horizon_days:  parseInt(horizonDays.value),
    })
    forecast.value = res.data
    activeTab.value = 'forecast'
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  } finally {
    loading.value = false
  }
}

const runModelComparison = async () => {
  loading.value = true
  error.value = null

  try {
    const res = await api.post('/forecasts/ml/compare-models', {
      panel_type_id: selectedPanelType.value,
      days: 90,
    })
    comparison.value = res.data.data
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  } finally {
    loading.value = false
  }
}

const loadAnomalies = async () => {
  loading.value = true
  error.value = null

  try {
    const res = await api.get('/forecasts/ml/anomalies', {
      params: { panel_type_id: selectedPanelType.value }
    })
    anomalyData.value = res.data.data
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  } finally {
    loading.value = false
  }
}

const loadPerformance = async () => {
  loading.value = true
  performanceError.value = null

  try {
    const res = await api.get('/forecasts/ml/performance')
    if (res.data.success) {
      performance.value = res.data
    } else {
      performanceError.value = res.data.message
    }
  } catch (e) {
    performanceError.value = e.response?.data?.message || e.message
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  try {
    const res = await api.get('/panel-types')
    panelTypes.value = res.data?.data ?? []
  } catch (e) {
    console.error('Could not load panel types', e)
  }
})
</script>

<style scoped>
.ml-dashboard {
  padding: 20px;
  background: #fafafa;
  border-radius: 8px;
}

.dashboard-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  flex-wrap: wrap;
  gap: 10px;
}

.dashboard-header h2 { margin: 0; font-size: 22px; }

.header-controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

.form-input { padding: 8px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; }
.form-input.small { width: 140px; }

.btn-primary, .btn-secondary {
  padding: 9px 18px; border: none; border-radius: 4px;
  cursor: pointer; font-size: 13px; font-weight: 600;
}
.btn-primary { background: #1976d2; color: white; }
.btn-primary:hover:not(:disabled) { background: var(--primary); }
.btn-primary:disabled { background: #bdbdbd; cursor: not-allowed; }
.btn-secondary { background: #757575; color: white; margin-bottom: 16px; }
.btn-secondary:hover:not(:disabled) { background: #616161; }

.tab-bar { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 2px solid #e0e0e0; }
.tab-btn {
  padding: 8px 16px; border: none; background: none;
  cursor: pointer; font-size: 13px; border-bottom: 3px solid transparent;
  transition: all 0.2s; margin-bottom: -2px;
}
.tab-btn.active { border-bottom-color: #1976d2; color: #1976d2; font-weight: 600; }
.tab-btn:hover { background: #f5f5f5; }

.tab-content { animation: fadeIn 0.2s ease-in; }

/* KPI grid */
.kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 20px; }
.kpi-card { background: white; border: 1px solid #ddd; border-radius: 6px; padding: 14px; text-align: center; }
.kpi-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
.kpi-value { font-size: 26px; font-weight: 700; color: #333; }
.kpi-sub { font-size: 11px; color: #aaa; margin-top: 4px; }

.risk-low { border-color: #81c784; background: #f1f8e9; }
.risk-medium { border-color: #ffb74d; background: #fff8e1; }
.risk-high { border-color: #ef5350; background: #ffebee; }

/* Insights row */
.insights-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 20px; }
.insight-card { background: white; border: 1px solid #ddd; border-radius: 6px; padding: 16px; }
.insight-card h4 { margin: 0 0 12px; font-size: 14px; }

.trend-indicator { display: flex; align-items: center; gap: 10px; font-size: 16px; margin-bottom: 8px; }
.trend-icon { font-size: 20px; }
.trend-label { font-weight: 600; }
.trend-detail { font-size: 12px; color: #888; }

.strong_up .trend-label { color: #2e7d32; }
.up .trend-label { color: #43a047; }
.stable .trend-label { color: #757575; }
.down .trend-label { color: #e53935; }
.strong_down .trend-label { color: #b71c1c; }

.strength-bar { height: 6px; background: #e0e0e0; border-radius: 3px; margin: 8px 0; }
.strength-fill { height: 100%; background: #1976d2; border-radius: 3px; max-width: 100%; }

.model-bars { display: grid; gap: 8px; }
.model-bar-row { display: flex; align-items: center; gap: 8px; font-size: 12px; }
.model-name { width: 110px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.bar-bg { flex: 1; height: 10px; background: #e0e0e0; border-radius: 5px; overflow: hidden; }
.bar-fill { height: 100%; background: #1976d2; border-radius: 5px; transition: width .4s; }
.bar-val { width: 40px; text-align: right; font-weight: 600; }

/* Mini bar chart */
.chart-section { background: white; border: 1px solid #ddd; border-radius: 6px; padding: 16px; }
.chart-section h4 { margin: 0 0 12px; font-size: 14px; }
.mini-chart { display: flex; align-items: flex-end; gap: 2px; height: 100px; padding: 8px 0; overflow-x: auto; }
.bar-col { display: flex; flex-direction: column; align-items: center; min-width: 8px; }
.bar { width: 8px; background: #1976d2; border-radius: 2px 2px 0 0; transition: height .3s; min-height: 4px; }
.bar-label { font-size: 9px; color: #aaa; margin-top: 2px; }
.chart-legend { font-size: 11px; color: #aaa; margin-top: 6px; }

/* Model comparison */
.comparison-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-top: 16px; }
.model-card { background: white; border: 1px solid #ddd; border-radius: 6px; padding: 14px; }
.model-card.best-model { border-color: #1976d2; box-shadow: 0 0 0 2px rgba(25,118,210,.2); }
.model-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.model-title { font-weight: 600; font-size: 13px; }
.best-badge { background: #1976d2; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; }
.metric-row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
.metric-row:last-child { border-bottom: none; }

/* Anomalies */
.anomaly-summary { display: flex; gap: 20px; margin: 16px 0; }
.anomaly-stat { text-align: center; }
.stat-value { font-size: 28px; font-weight: 700; }
.stat-label { font-size: 12px; color: #888; }
.anomaly-list h5 { margin: 0 0 10px; }
.anomaly-item { display: flex; gap: 12px; align-items: center; padding: 10px; border-radius: 4px; margin-bottom: 8px; font-size: 13px; }
.anomaly-item.spike { background: #fff3e0; border-left: 4px solid #ff9800; }
.anomaly-item.dip { background: var(--primary-tint); border-left: 4px solid #1976d2; }
.anomaly-type { margin-left: auto; font-weight: 600; font-size: 11px; }
.no-anomalies { padding: 16px; color: #4caf50; font-weight: 600; }

/* Performance */
.perf-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 14px; margin-top: 16px; }
.perf-card { background: white; border: 1px solid #ddd; border-radius: 6px; padding: 16px; text-align: center; }
.perf-card.highlight { border-color: #1976d2; }
.perf-value { font-size: 28px; font-weight: 700; color: #333; }
.perf-label { font-size: 12px; color: #888; margin-top: 6px; }
.no-performance { padding: 16px; color: #888; }

.error-alert { background: #ffebee; border: 1px solid #ef5350; color: #c62828; padding: 12px; border-radius: 4px; margin-top: 14px; }

@keyframes fadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }

@media (max-width: 768px) {
  .dashboard-header { flex-direction: column; align-items: flex-start; }
  .kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
