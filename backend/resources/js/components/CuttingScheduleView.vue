<template>
  <div class="cutting-schedule">
    <h2>Cutting Schedule</h2>

    <div v-if="loading" class="loading">Loading cutting schedule...</div>
    <div v-else-if="error" class="error">{{ error }}</div>

    <div v-else-if="schedule" class="schedule-content">
      <div class="schedule-header">
        <div class="header-item">
          <span class="label">Total Material Length:</span>
          <span class="value">{{ schedule.total_material_length }}mm</span>
        </div>
        <div class="header-item">
          <span class="label">Total Items:</span>
          <span class="value">{{ schedule.total_items }}</span>
        </div>
        <div class="header-item">
          <span class="label">Waste %:</span>
          <span class="value">{{ schedule.waste_percentage }}%</span>
        </div>
      </div>

      <div class="optimization-summary">
        <h3>Optimization Summary</h3>
        <div class="summary-grid">
          <div class="summary-item">
            <span class="label">Double Cut Items:</span>
            <span class="value">{{ schedule.optimization.double_cut_items }}</span>
          </div>
          <div class="summary-item">
            <span class="label">Single Cut Items:</span>
            <span class="value">{{ schedule.optimization.single_cut_items }}</span>
          </div>
          <div class="summary-item">
            <span class="label">Double Cut %:</span>
            <span class="value">{{ schedule.optimization.double_cut_percentage }}%</span>
          </div>
          <div class="summary-item">
            <span class="label">Efficiency:</span>
            <span class="value">{{ schedule.optimization.optimization_efficiency }}</span>
          </div>
        </div>
      </div>

      <div class="schedule-items">
        <h3>Cutting Operations</h3>
        <table class="items-table">
          <thead>
            <tr>
              <th>Panel Type</th>
              <th>Method</th>
              <th>Quantity</th>
              <th>Per Roll</th>
              <th>Rolls Needed</th>
              <th>Material (L × W)</th>
              <th>Total Length</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, index) in schedule.schedule_items" :key="index" :class="item.method">
              <td>{{ item.panel_type }}</td>
              <td><span class="method-badge" :class="item.method">{{ formatMethod(item.method) }}</span></td>
              <td>{{ item.quantity }}</td>
              <td>{{ item.per_roll }}</td>
              <td>{{ item.rolls_needed }}</td>
              <td>{{ item.material_length }}mm × {{ item.material_width }}mm</td>
              <td>{{ item.total_length }}mm</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="schedule-actions">
        <button @click="downloadInstructions" class="btn-primary">📄 Download Instructions</button>
      </div>
    </div>

    <div v-else class="no-schedule">
      <p>No cutting schedule available. Calculate schedule first.</p>
      <button @click="calculateSchedule" class="btn-primary">Calculate Schedule</button>
    </div>
  </div>
</template>

<script>
import productionService from '../services/productionService'

export default {
  name: 'CuttingScheduleView',
  props: {
    batchId: {
      type: Number,
      required: true
    }
  },
  data() {
    return {
      schedule: null,
      loading: true,
      error: null,
      calculating: false
    }
  },
  mounted() {
    this.fetchSchedule()
  },
  methods: {
    async fetchSchedule() {
      try {
        this.loading = true
        this.error = null
        const response = await productionService.getCuttingScheduleJson(this.batchId)
        this.schedule = response.data.data
      } catch (err) {
        if (err.response?.status === 404) {
          this.schedule = null
        } else {
          this.error = err.response?.data?.message || 'Failed to load schedule'
        }
      } finally {
        this.loading = false
      }
    },
    async calculateSchedule() {
      try {
        this.calculating = true
        this.error = null
        await productionService.calculateCuttingSchedule(this.batchId)
        this.fetchSchedule()
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to calculate schedule'
      } finally {
        this.calculating = false
      }
    },
    async downloadInstructions() {
      try {
        const response = await productionService.getCuttingInstructions(this.batchId)
        const blob = new Blob([response.data], { type: 'text/plain' })
        const url = window.URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = `cutting-schedule-${this.batchId}.txt`
        document.body.appendChild(link)
        link.click()
        window.URL.revokeObjectURL(url)
        document.body.removeChild(link)
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to download instructions'
      }
    },
    formatMethod(method) {
      return method === 'double' ? 'Double Cut' : 'Single Cut'
    }
  }
}
</script>

<style scoped>
.cutting-schedule {
  padding: 20px;
  background-color: #f9f9f9;
  border-radius: 8px;
}

.loading,
.error,
.no-schedule {
  padding: 20px;
  text-align: center;
  font-size: 16px;
}

.error {
  color: #d32f2f;
  background-color: #ffebee;
  border-radius: 4px;
}

.no-schedule {
  background-color: white;
  border-radius: 4px;
}

.schedule-content {
  background-color: white;
  border-radius: 8px;
  padding: 20px;
}

.schedule-header {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
  margin-bottom: 20px;
  padding: 15px;
  background-color: #f5f5f5;
  border-radius: 4px;
}

.header-item {
  display: flex;
  flex-direction: column;
}

.label {
  font-size: 12px;
  color: #666;
  font-weight: 600;
  margin-bottom: 4px;
}

.value {
  font-size: 18px;
  color: #1976d2;
  font-weight: bold;
}

.optimization-summary {
  margin-bottom: 30px;
  padding: 15px;
  background-color: #e3f2fd;
  border-radius: 4px;
  border-left: 4px solid #1976d2;
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 15px;
  margin-top: 10px;
}

.summary-item {
  display: flex;
  flex-direction: column;
  padding: 10px;
  background-color: white;
  border-radius: 4px;
}

.schedule-items {
  margin-bottom: 20px;
}

.items-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 10px;
}

.items-table th {
  background-color: #f5f5f5;
  padding: 12px;
  text-align: left;
  font-weight: 600;
  font-size: 13px;
  border-bottom: 2px solid #ddd;
}

.items-table td {
  padding: 12px;
  border-bottom: 1px solid #eee;
  font-size: 13px;
}

.items-table tr.double {
  background-color: #f0f7ff;
}

.items-table tr.double:hover {
  background-color: #e3f2fd;
}

.items-table tr.single:hover {
  background-color: #f5f5f5;
}

.method-badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
}

.method-badge.double {
  background-color: #c8e6c9;
  color: #2e7d32;
}

.method-badge.single {
  background-color: #e0e0e0;
  color: #424242;
}

.schedule-actions {
  display: flex;
  justify-content: center;
  gap: 10px;
  margin-top: 20px;
}

.btn-primary {
  padding: 10px 20px;
  background-color: #1976d2;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
}

.btn-primary:hover {
  background-color: #1565c0;
}

.btn-primary:disabled {
  background-color: #ccc;
  cursor: not-allowed;
}
</style>
