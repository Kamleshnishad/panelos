<template>
  <div class="batch-detail">
    <div class="detail-header">
      <h2>Batch {{ batch?.batch_no }}</h2>
      <router-link to="/batches" class="btn-back">← Back</router-link>
    </div>

    <div v-if="loading" class="loading">Loading batch details...</div>
    <div v-else-if="error" class="error">{{ error }}</div>

    <div v-else-if="batch" class="detail-content">
      <div class="batch-summary">
        <div class="summary-grid">
          <div class="summary-item">
            <span class="label">Order:</span>
            <router-link :to="`/orders/${batch.order_id}`" class="value-link">{{ batch.order?.order_no }}</router-link>
          </div>
          <div class="summary-item">
            <span class="label">Status:</span>
            <span :class="['status', batch.status]">{{ formatStatus(batch.status) }}</span>
          </div>
          <div class="summary-item">
            <span class="label">Quantity Progress:</span>
            <span class="value">{{ batch.completed_quantity }} / {{ batch.planned_quantity }}</span>
          </div>
          <div class="summary-item">
            <span class="label">Progress:</span>
            <span class="value">{{ getProgressPercent(batch) }}%</span>
          </div>
        </div>
      </div>

      <div class="batch-actions">
        <button
          v-if="batch.status === 'draft'"
          @click="startProduction"
          class="btn-primary"
          :disabled="actionLoading">
          {{ actionLoading ? 'Starting...' : 'Start Production' }}
        </button>
        <button
          v-if="batch.status === 'in_progress'"
          @click="completeBatch"
          class="btn-success"
          :disabled="actionLoading">
          {{ actionLoading ? 'Completing...' : 'Complete Batch' }}
        </button>
      </div>

      <div class="stage-section">
        <h3>Production Stages</h3>
        <BatchStageTracker :batch-id="batch.id" />
      </div>

      <div class="cutting-schedule-section">
        <h3>Cutting Schedule</h3>
        <CuttingScheduleView :batch-id="batch.id" />
      </div>

      <div class="qc-section">
        <h3>Quality Control</h3>
        <QCForm :batch-id="batch.id" @qc-submitted="onQCSubmitted" />
      </div>
    </div>
  </div>
</template>

<script>
import productionService from '../services/productionService'
import BatchStageTracker from './BatchStageTracker.vue'
import CuttingScheduleView from './CuttingScheduleView.vue'
import QCForm from './QCForm.vue'

export default {
  name: 'BatchDetail',
  components: {
    BatchStageTracker,
    CuttingScheduleView,
    QCForm
  },
  props: {
    batchId: {
      type: [String, Number],
      required: true
    }
  },
  data() {
    return {
      batch: null,
      loading: true,
      error: null,
      actionLoading: false
    }
  },
  mounted() {
    this.fetchBatchDetail()
  },
  methods: {
    async fetchBatchDetail() {
      try {
        this.loading = true
        this.error = null
        const response = await productionService.getBatch(this.batchId)
        this.batch = response.data.data
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to load batch details'
      } finally {
        this.loading = false
      }
    },
    async startProduction() {
      try {
        this.actionLoading = true
        this.error = null
        await productionService.startProduction(this.batchId)
        this.fetchBatchDetail()
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to start production'
      } finally {
        this.actionLoading = false
      }
    },
    async completeBatch() {
      try {
        this.actionLoading = true
        this.error = null
        await productionService.completeBatch(this.batchId)
        this.fetchBatchDetail()
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to complete batch'
      } finally {
        this.actionLoading = false
      }
    },
    onQCSubmitted() {
      this.fetchBatchDetail()
    },
    formatStatus(status) {
      return status.replace('_', ' ').toUpperCase()
    },
    getProgressPercent(batch) {
      if (batch.planned_quantity === 0) return 0
      return Math.round((batch.completed_quantity / batch.planned_quantity) * 100)
    }
  }
}
</script>

<style scoped>
.batch-detail {
  padding: 20px;
  background-color: #f9f9f9;
  min-height: 100vh;
}

.detail-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 30px;
}

.btn-back {
  padding: 8px 16px;
  background-color: #e0e0e0;
  color: #333;
  text-decoration: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
}

.btn-back:hover {
  background-color: #bdbdbd;
}

.loading,
.error {
  padding: 20px;
  text-align: center;
  font-size: 16px;
  background-color: white;
  border-radius: 8px;
}

.error {
  color: #d32f2f;
  background-color: #ffebee;
  border: 1px solid #ef5350;
}

.detail-content {
  background-color: white;
  border-radius: 8px;
  padding: 20px;
}

.batch-summary {
  margin-bottom: 20px;
  padding: 15px;
  background-color: #f5f5f5;
  border-radius: 4px;
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
}

.summary-item {
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
  font-size: 16px;
  color: #333;
  font-weight: 500;
}

.value-link {
  font-size: 16px;
  color: #1976d2;
  font-weight: 500;
  text-decoration: none;
}

.value-link:hover {
  text-decoration: underline;
}

.status {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
  width: fit-content;
}

.status.draft {
  background-color: #e0e0e0;
  color: #333;
}

.status.in_progress {
  background-color: #cce5ff;
  color: #004085;
}

.status.qc_pending {
  background-color: #fff3cd;
  color: #856404;
}

.status.qc_passed {
  background-color: #d4edda;
  color: #155724;
}

.status.qc_failed {
  background-color: #f8d7da;
  color: #721c24;
}

.batch-actions {
  display: flex;
  gap: 10px;
  margin: 20px 0;
  padding: 15px;
  background-color: #f5f5f5;
  border-radius: 4px;
}

.btn-primary,
.btn-success {
  padding: 10px 20px;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
}

.btn-primary {
  background-color: #1976d2;
}

.btn-primary:hover:not(:disabled) {
  background-color: #1565c0;
}

.btn-success {
  background-color: #4caf50;
}

.btn-success:hover:not(:disabled) {
  background-color: #45a049;
}

.btn-primary:disabled,
.btn-success:disabled {
  background-color: #ccc;
  cursor: not-allowed;
}

.stage-section,
.cutting-schedule-section,
.qc-section {
  margin-top: 30px;
  padding: 20px;
  background-color: #f9f9f9;
  border-radius: 4px;
}

.stage-section h3,
.cutting-schedule-section h3,
.qc-section h3 {
  margin-top: 0;
  color: #333;
}
</style>
