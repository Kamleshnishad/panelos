<template>
  <div>
    <div class="page-header"><div><div class="page-title">🤖 ML Demand Forecasting</div><div class="page-subtitle">AI-powered inventory & demand predictions</div></div></div>

    <div class="card">
      <div class="card-header"><div class="card-title">Generate ML Forecast</div></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Panel Type ID</label><input v-model="form.panel_type_id" type="number" class="form-control" placeholder="e.g. 1" /></div>
        <div class="form-group"><label class="form-label">Horizon (days)</label>
          <select v-model="form.horizon_days" class="form-control">
            <option value="7">7 days</option><option value="14">14 days</option><option value="30" selected>30 days</option><option value="60">60 days</option><option value="90">90 days</option>
          </select>
        </div>
        <div class="form-group" style="display:flex;align-items:flex-end;gap:8px">
          <button class="btn btn-primary" @click="runForecast" :disabled="loading">{{ loading ? 'Running...' : '▶ Run Forecast' }}</button>
          <button class="btn btn-outline" @click="runCompare" :disabled="loading">🔬 Compare Models</button>
          <button class="btn btn-outline" @click="runAnomalies" :disabled="loading">⚠️ Anomalies</button>
        </div>
      </div>
    </div>

    <div v-if="loading" class="loading"><div class="spinner"></div></div>

    <!-- Forecast Results -->
    <template v-if="forecast && !loading">
      <div class="kpi-grid">
        <div class="kpi-card blue"><div class="kpi-label">Total Demand</div><div class="kpi-value">{{ forecast.total_predicted_demand }}</div><div class="kpi-sub">in {{ forecast.horizon_days }} days</div></div>
        <div class="kpi-card"><div class="kpi-label">Daily Average</div><div class="kpi-value">{{ forecast.average_daily_demand }}</div><div class="kpi-sub">units/day</div></div>
        <div class="kpi-card" :class="forecast.confidence_score>=80?'green':forecast.confidence_score>=60?'orange':'red'">
          <div class="kpi-label">Confidence</div><div class="kpi-value">{{ forecast.confidence_score }}%</div>
        </div>
        <div class="kpi-card" :class="{'green':forecast.risk_level==='low','orange':forecast.risk_level==='medium','red':forecast.risk_level==='high'}">
          <div class="kpi-label">Risk Level</div><div class="kpi-value">{{ forecast.risk_level?.toUpperCase() }}</div><div class="kpi-sub">Stock: {{ forecast.current_stock }}</div>
        </div>
        <div class="kpi-card orange"><div class="kpi-label">Reorder Qty</div><div class="kpi-value">{{ forecast.reorder_quantity }}</div><div class="kpi-sub">by {{ forecast.recommended_order_date }}</div></div>
        <div class="kpi-card" :class="forecast.anomalies_detected>0?'red':''">
          <div class="kpi-label">Anomalies</div><div class="kpi-value">{{ forecast.anomalies_detected }}</div><div class="kpi-sub">detected</div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="card">
          <div class="card-header"><div class="card-title">📈 Trend</div></div>
          <div style="font-size:32px;margin-bottom:8px">{{ trendIcon(forecast.trend?.direction) }}</div>
          <div style="font-size:18px;font-weight:600">{{ forecast.trend?.direction?.replace('_',' ') }}</div>
          <div class="text-muted" style="margin-top:6px">Slope: {{ forecast.trend?.slope }} | Change: {{ forecast.trend?.pct_change }}%/day</div>
        </div>
        <div class="card">
          <div class="card-header"><div class="card-title">🔄 Seasonality</div></div>
          <div v-if="forecast.seasonality?.detected" style="color:var(--success);font-weight:600;margin-bottom:8px">✅ Detected ({{ forecast.seasonality.period }}-day cycle)</div>
          <div v-else style="color:var(--gray-500)">➖ No seasonality detected</div>
          <div class="text-muted" style="margin-top:6px">Strength: {{ ((forecast.seasonality?.strength||0)*100).toFixed(1) }}%</div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><div class="card-title">⚙️ Model Ensemble Breakdown</div></div>
        <div class="table-wrap"><table>
          <thead><tr><th>Model</th><th>Total Predicted</th></tr></thead>
          <tbody>
            <tr v-for="(val, name) in modelBreakdown" :key="name"><td>{{ fmtModel(name) }}</td><td>{{ val }}</td></tr>
          </tbody>
        </table></div>
      </div>
    </template>

    <!-- Model Comparison -->
    <template v-if="comparison && !loading">
      <div class="card">
        <div class="card-header"><div class="card-title">🔬 Model Comparison</div><span style="font-size:13px;color:var(--gray-500)">Recommended: <strong>{{ comparison.recommended }}</strong></span></div>
        <div class="table-wrap"><table>
          <thead><tr><th>Model</th><th>Accuracy %</th><th>MAE</th><th>RMSE</th><th>MAPE %</th></tr></thead>
          <tbody>
            <tr v-for="(m, name) in comparison.models" :key="name" :style="name===comparison.recommended?'background:var(--primary-light);font-weight:600':''">
              <td>{{ fmtModel(name) }} {{ name===comparison.recommended?'⭐':'' }}</td>
              <td :style="m.accuracy>=80?'color:var(--success)':m.accuracy>=60?'color:var(--warning)':'color:var(--danger)'">{{ m.accuracy }}%</td>
              <td>{{ m.mae }}</td><td>{{ m.rmse }}</td><td>{{ m.mape }}%</td>
            </tr>
          </tbody>
        </table></div>
      </div>
    </template>

    <!-- Anomalies -->
    <template v-if="anomalies && !loading">
      <div class="card">
        <div class="card-header"><div class="card-title">⚠️ Anomaly Detection</div></div>
        <div class="kpi-grid">
          <div class="kpi-card"><div class="kpi-label">Data Points</div><div class="kpi-value">{{ anomalies.data_points }}</div></div>
          <div class="kpi-card" :class="anomalies.anomalies.length?'red':''"><div class="kpi-label">Anomalies Found</div><div class="kpi-value">{{ anomalies.anomalies.length }}</div></div>
          <div class="kpi-card"><div class="kpi-label">Confidence</div><div class="kpi-value">{{ anomalies.confidence }}%</div></div>
        </div>
        <div v-if="anomalies.anomalies.length" class="table-wrap"><table>
          <thead><tr><th>Day</th><th>Quantity</th><th>Z-Score</th><th>Type</th></tr></thead>
          <tbody><tr v-for="a in anomalies.anomalies" :key="a.day_index" :style="a.type==='spike'?'background:#fff3e0':'background:#e3f2fd'">
            <td>Day {{ a.day_index+1 }}</td><td>{{ a.quantity }}</td><td>{{ a.z_score }}</td>
            <td><span :class="'badge badge-'+(a.type==='spike'?'warning':'sent')">{{ a.type }}</span></td>
          </tr></tbody>
        </table></div>
        <div v-else class="empty-state" style="padding:20px"><div class="icon">✅</div><p>No anomalies detected</p></div>
      </div>
    </template>

    <div v-if="error" class="alert alert-error">{{ error }}</div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import api from '@/services/api'
const form = ref({ panel_type_id: '', horizon_days: '30' })
const loading = ref(false); const forecast = ref(null); const comparison = ref(null); const anomalies = ref(null); const error = ref('')
const modelBreakdown = computed(() => { if (!forecast.value?.model_breakdown) return {}; const { ensemble, ...rest } = forecast.value.model_breakdown; return rest })
const trendIcon = (d) => ({ strong_up:'⬆️⬆️', up:'⬆️', stable:'➡️', down:'⬇️', strong_down:'⬇️⬇️' }[d] || '➡️')
const fmtModel = (n) => ({ linear_regression:'Linear Regression', exponential_smoothing:'Exp. Smoothing', moving_average:'Moving Average', seasonal_decomposition:'Seasonal' }[n] || n)
const runForecast = async () => {
  loading.value = true; forecast.value = null; comparison.value = null; anomalies.value = null; error.value = ''
  try { const { data } = await api.post('/forecasts/ml', { panel_type_id: form.value.panel_type_id, horizon_days: parseInt(form.value.horizon_days) }); forecast.value = data }
  catch (e) { error.value = e.response?.data?.message || 'Forecast failed — need at least 7 days of sales data' }
  finally { loading.value = false }
}
const runCompare = async () => {
  loading.value = true; forecast.value = null; comparison.value = null; anomalies.value = null; error.value = ''
  try { const { data } = await api.post('/forecasts/ml/compare-models', { panel_type_id: form.value.panel_type_id }); comparison.value = data.data }
  catch (e) { error.value = e.response?.data?.message || 'Need at least 14 days of data' }
  finally { loading.value = false }
}
const runAnomalies = async () => {
  loading.value = true; forecast.value = null; comparison.value = null; anomalies.value = null; error.value = ''
  try { const { data } = await api.get('/forecasts/ml/anomalies', { params: { panel_type_id: form.value.panel_type_id } }); anomalies.value = data.data }
  catch (e) { error.value = e.response?.data?.message || 'Anomaly detection failed' }
  finally { loading.value = false }
}
</script>
