<template>
  <div class="trend-viewer">
    <h2>Sales Trend Analysis</h2>

    <div class="controls">
      <select v-model="selectedPeriod" @change="generateTrends" class="period-select">
        <option value="7">Last 7 Days</option>
        <option value="30">Last 30 Days</option>
        <option value="90">Last 90 Days</option>
        <option value="365">Last 12 Months</option>
      </select>
      <select v-model="selectedPanel" @change="generateTrends" class="panel-select">
        <option value="">All Panels</option>
        <option v-for="type in panelTypes" :key="type.id" :value="type.id">
          {{ type.type }}
        </option>
      </select>
      <button @click="generateTrends" class="btn-primary">Analyze</button>
    </div>

    <div class="trends-grid">
      <div v-for="trend in trends" :key="trend.id" class="trend-card">
        <div class="card-header">
          <h3>{{ trend.panelType?.type || 'Overall' }}</h3>
          <span :class="['trend-badge', `direction-${trend.trend_direction}`]">
            {{ trend.trend_direction }}
          </span>
        </div>

        <div class="trend-content">
          <div class="stat">
            <span class="stat-label">Growth Rate</span>
            <span :class="['stat-value', { positive: trend.growth_rate > 0 }]">
              {{ trend.growth_rate.toFixed(2) }}%
            </span>
          </div>

          <div class="stat">
            <span class="stat-label">Volatility</span>
            <span class="stat-value">{{ trend.volatility.toFixed(4) }}</span>
          </div>

          <div class="stat">
            <span class="stat-label">Average Sales</span>
            <span class="stat-value">{{ trend.average_sales }}</span>
          </div>

          <div class="stat">
            <span class="stat-label">Peak / Low</span>
            <span class="stat-value">{{ trend.peak_sales }} / {{ trend.low_sales }}</span>
          </div>

          <div class="stat" v-if="trend.year_over_year_change !== null">
            <span class="stat-label">Year-over-Year</span>
            <span :class="['stat-value', { positive: trend.year_over_year_change > 0 }]">
              {{ trend.year_over_year_change.toFixed(2) }}%
            </span>
          </div>

          <div class="stat">
            <span class="stat-label">Seasonal Pattern</span>
            <span class="stat-value">Month {{ trend.seasonal_pattern }}</span>
          </div>
        </div>

        <div class="trend-chart">
          <div class="mini-chart">
            <div
              v-for="i in 12"
              :key="i"
              class="chart-bar"
              :style="{ height: (Math.random() * 80 + 20) + '%' }"
            ></div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="trends.length === 0" class="empty-state">
      <p>No trend data available. Click "Analyze" to generate trends.</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { analyticsService } from '@/services/api'

const trends = ref([])
const panelTypes = ref([])
const selectedPeriod = ref('30')
const selectedPanel = ref('')

const generateTrends = async () => {
  try {
    const data = await analyticsService.generateTrendAnalysis({
      panel_type_id: selectedPanel.value || null,
      period_days: parseInt(selectedPeriod.value)
    })
    trends.value = data || []
  } catch (error) {
    console.error('Failed to generate trends:', error)
  }
}

const fetchTrends = async () => {
  try {
    const data = await analyticsService.getTrendAnalysis({
      panel_type_id: selectedPanel.value || null,
      period_days: parseInt(selectedPeriod.value)
    })
    trends.value = data || []
  } catch (error) {
    console.error('Failed to fetch trends:', error)
  }
}

onMounted(() => {
  fetchTrends()
})
</script>

<style scoped>
.trend-viewer {
  padding: 20px;
}

.controls {
  display: flex;
  gap: 10px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.period-select,
.panel-select {
  padding: 8px 12px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
}

.btn-primary {
  padding: 8px 16px;
  background-color: #1976d2;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
}

.trends-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
  gap: 20px;
}

.trend-card {
  background: white;
  border: 1px solid #ddd;
  border-radius: 4px;
  padding: 20px;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 15px;
  padding-bottom: 10px;
  border-bottom: 2px solid #1976d2;
}

.card-header h3 {
  margin: 0;
  font-size: 16px;
}

.trend-badge {
  padding: 4px 8px;
  border-radius: 3px;
  font-size: 12px;
  font-weight: 600;
  text-transform: capitalize;
}

.direction-upward {
  background-color: #e8f5e9;
  color: #388e3c;
}

.direction-downward {
  background-color: #ffebee;
  color: #d32f2f;
}

.direction-stable {
  background-color: var(--primary-tint);
  color: #1976d2;
}

.trend-content {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
  margin-bottom: 15px;
}

.stat {
  padding: 10px;
  background: #f9f9f9;
  border-radius: 4px;
}

.stat-label {
  display: block;
  font-size: 12px;
  color: #666;
  margin-bottom: 3px;
}

.stat-value {
  display: block;
  font-size: 16px;
  font-weight: bold;
  color: #333;
}

.stat-value.positive {
  color: #388e3c;
}

.trend-chart {
  margin-top: 15px;
  padding-top: 15px;
  border-top: 1px solid #e0e0e0;
}

.mini-chart {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  height: 80px;
  gap: 3px;
}

.chart-bar {
  flex: 1;
  background: linear-gradient(180deg, #1976d2, #64b5f6);
  border-radius: 2px 2px 0 0;
}

.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: #999;
}

@media (max-width: 768px) {
  .trends-grid {
    grid-template-columns: 1fr;
  }

  .trend-content {
    grid-template-columns: 1fr;
  }
}
</style>
