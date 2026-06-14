<template>
  <div class="forecasting-dashboard">
    <h2>Demand Forecasting</h2>

    <div class="forecast-controls">
      <button @click="generateForecasts" class="btn-primary">Generate Forecasts</button>
      <button @click="generateDemandForecast" class="btn-primary">Generate Demand</button>
      <select v-model="selectedPanelType" class="panel-select">
        <option value="">All Panel Types</option>
        <option v-for="type in panelTypes" :key="type.id" :value="type.id">
          {{ type.type }}
        </option>
      </select>
    </div>

    <div class="forecast-grid">
      <div class="forecast-card">
        <h3>Upcoming Reorders</h3>
        <div v-if="upcomingReorders.length" class="reorder-list">
          <div v-for="reorder in upcomingReorders" :key="reorder.id" class="reorder-item">
            <div class="reorder-panel">{{ reorder.panelType?.type }}</div>
            <div class="reorder-date">{{ formatDate(reorder.recommended_order_date) }}</div>
            <div class="reorder-qty">Qty: {{ reorder.reorder_quantity }}</div>
            <div :class="['reorder-risk', `risk-${reorder.risk_level}`]">
              {{ reorder.risk_level }}
            </div>
          </div>
        </div>
        <div v-else class="empty">No upcoming reorders</div>
      </div>

      <div class="forecast-card">
        <h3>Demand Forecast</h3>
        <div v-if="demandForecasts.length" class="forecast-list">
          <div v-for="forecast in demandForecasts" :key="forecast.id" class="forecast-item">
            <div class="forecast-panel">{{ forecast.panelType?.type }}</div>
            <div class="forecast-demand">Predicted: {{ forecast.predicted_demand }} units</div>
            <div class="forecast-seasonal">Seasonal: {{ (forecast.seasonal_factor).toFixed(2) }}x</div>
            <div class="forecast-stock">Stock: {{ forecast.current_stock }} units</div>
          </div>
        </div>
        <div v-else class="empty">No forecasts generated</div>
      </div>

      <div class="forecast-card">
        <h3>Stock Status</h3>
        <div v-if="demandForecasts.length" class="status-list">
          <div v-for="forecast in demandForecasts" :key="forecast.id" class="status-item">
            <div class="status-name">{{ forecast.panelType?.type }}</div>
            <div class="status-bar">
              <div class="status-fill" :style="{ width: getStockPercentage(forecast) + '%' }"></div>
            </div>
            <div class="status-text">{{ forecast.current_stock }} / {{ forecast.predicted_demand }}</div>
          </div>
        </div>
        <div v-else class="empty">Generate forecasts to see stock status</div>
      </div>

      <div class="forecast-card">
        <h3>Forecast Metrics</h3>
        <div v-if="demandForecasts.length" class="metrics-list">
          <div class="metric">
            <span class="metric-label">Avg Confidence:</span>
            <span class="metric-value">{{ getAverageConfidence() }}%</span>
          </div>
          <div class="metric">
            <span class="metric-label">High Risk Items:</span>
            <span class="metric-value">{{ countHighRisk() }}</span>
          </div>
          <div class="metric">
            <span class="metric-label">Total Reorder Value:</span>
            <span class="metric-value">${{ getTotalReorderValue() }}</span>
          </div>
        </div>
        <div v-else class="empty">No forecast data</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { analyticsService } from '@/services/api'

const upcomingReorders = ref([])
const demandForecasts = ref([])
const panelTypes = ref([])
const selectedPanelType = ref('')

const generateForecasts = async () => {
  try {
    await analyticsService.generateInventoryForecast({
      panel_type_id: selectedPanelType.value || null,
      days_ahead: 30
    })
  } catch (error) {
    console.error('Failed to generate forecasts:', error)
  }
}

const generateDemandForecast = async () => {
  try {
    await analyticsService.generateDemandForecast({
      panel_type_id: selectedPanelType.value || null,
      forecast_period: 30
    })
    await fetchDemandForecasts()
  } catch (error) {
    console.error('Failed to generate demand forecast:', error)
  }
}

const fetchDemandForecasts = async () => {
  try {
    const data = await analyticsService.getDemandForecast({
      panel_type_id: selectedPanelType.value || null
    })
    demandForecasts.value = data || []
  } catch (error) {
    console.error('Failed to fetch forecasts:', error)
  }
}

const fetchUpcomingReorders = async () => {
  try {
    const data = await analyticsService.getUpcomingReorders({ days_ahead: 30 })
    upcomingReorders.value = data || []
  } catch (error) {
    console.error('Failed to fetch reorders:', error)
  }
}

const getStockPercentage = (forecast) => {
  if (forecast.predicted_demand === 0) return 100
  return Math.min(100, (forecast.current_stock / forecast.predicted_demand) * 100)
}

const getAverageConfidence = () => {
  if (demandForecasts.value.length === 0) return 0
  const total = demandForecasts.value.reduce((sum, f) => sum + (f.confidence_score || 85), 0)
  return Math.round(total / demandForecasts.value.length)
}

const countHighRisk = () => {
  return demandForecasts.value.filter(f => f.risk_level === 'high').length
}

const getTotalReorderValue = () => {
  return demandForecasts.value.reduce((sum, f) => sum + (f.reorder_quantity || 0), 0) * 50
}

const formatDate = (date) => new Date(date).toLocaleDateString()

onMounted(() => {
  fetchDemandForecasts()
  fetchUpcomingReorders()
})
</script>

<style scoped>
.forecasting-dashboard {
  padding: 20px;
}

.forecast-controls {
  display: flex;
  gap: 10px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.forecast-controls button {
  padding: 10px 16px;
  background-color: #1976d2;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
}

.panel-select {
  padding: 8px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
}

.forecast-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
}

.forecast-card {
  background: white;
  padding: 20px;
  border: 1px solid #ddd;
  border-radius: 4px;
}

.forecast-card h3 {
  margin-bottom: 15px;
  font-size: 16px;
}

.reorder-list,
.forecast-list,
.status-list,
.metrics-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.reorder-item,
.forecast-item,
.status-item {
  padding: 10px;
  background: #f9f9f9;
  border-radius: 4px;
  border: 1px solid #e0e0e0;
}

.reorder-panel,
.forecast-panel,
.status-name {
  font-weight: 600;
  margin-bottom: 5px;
}

.reorder-date,
.reorder-qty,
.forecast-demand,
.forecast-seasonal,
.forecast-stock {
  font-size: 12px;
  color: #666;
  margin-bottom: 3px;
}

.reorder-risk {
  display: inline-block;
  padding: 2px 6px;
  border-radius: 3px;
  font-size: 11px;
  font-weight: 600;
}

.risk-low {
  background-color: #e8f5e9;
  color: #388e3c;
}

.risk-medium {
  background-color: #fff3e0;
  color: #f57c00;
}

.risk-high {
  background-color: #ffebee;
  color: #d32f2f;
}

.status-bar {
  height: 20px;
  background: #e0e0e0;
  border-radius: 3px;
  overflow: hidden;
  margin: 8px 0;
}

.status-fill {
  height: 100%;
  background: linear-gradient(90deg, #1976d2, #388e3c);
  transition: width 0.3s ease;
}

.status-text {
  font-size: 12px;
  color: #666;
}

.metric {
  display: flex;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid #f0f0f0;
}

.metric:last-child {
  border-bottom: none;
}

.metric-label {
  font-weight: 600;
}

.metric-value {
  font-weight: bold;
  color: #1976d2;
}

.empty {
  text-align: center;
  padding: 20px;
  color: #999;
}

@media (max-width: 768px) {
  .forecast-grid {
    grid-template-columns: 1fr;
  }

  .forecast-controls {
    flex-direction: column;
  }
}
</style>
