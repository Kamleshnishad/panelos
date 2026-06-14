<template>
  <div class="stage-tracker">
    <h3>Production Progress</h3>

    <div v-if="loading" class="loading">Loading progress...</div>
    <div v-else-if="error" class="error">{{ error }}</div>

    <div v-else class="stages">
      <div v-for="(stage, index) in stages" :key="stage.stage_id" class="stage-container">
        <div :class="['stage-node', stage.status]">
          <div class="stage-icon">
            {{ getStageIcon(stage.status) }}
          </div>
          <div class="stage-name">{{ stage.stage_name }}</div>
        </div>

        <div v-if="stage.status === 'in_progress'" class="stage-actions">
          <button @click="completeStage(stage.stage_id)" class="btn-complete">Complete</button>
        </div>
        <div v-else-if="stage.status === 'pending' && canStartStage(index)" class="stage-actions">
          <button @click="startStage(stage.stage_id)" class="btn-start">Start</button>
        </div>

        <div v-if="stage.completed_at" class="stage-info">
          <small>{{ formatDuration(stage.duration_minutes) }}</small>
        </div>

        <div v-if="index < stages.length - 1" class="stage-connector"></div>
      </div>
    </div>

    <div v-if="stats" class="stats">
      <div class="stat-item">
        <span class="stat-label">Completed:</span>
        <span class="stat-value">{{ completedCount }} / {{ stages.length }}</span>
      </div>
      <div class="stat-item">
        <span class="stat-label">Progress:</span>
        <span class="stat-value">{{ progressPercent }}%</span>
      </div>
    </div>
  </div>
</template>

<script>
import productionService from '../services/productionService'

export default {
  name: 'BatchStageTracker',
  props: {
    batchId: {
      type: Number,
      required: true
    }
  },
  data() {
    return {
      stages: [],
      loading: true,
      error: null,
      completedCount: 0
    }
  },
  computed: {
    progressPercent() {
      return Math.round((this.completedCount / this.stages.length) * 100)
    },
    stats() {
      return this.stages.length > 0
    }
  },
  mounted() {
    this.fetchProgress()
    this.interval = setInterval(this.fetchProgress, 5000)
  },
  beforeUnmount() {
    clearInterval(this.interval)
  },
  methods: {
    async fetchProgress() {
      try {
        this.loading = true
        const response = await productionService.getBatchProgress(this.batchId)
        this.stages = response.data.data
        this.completedCount = this.stages.filter(s => s.status === 'completed').length
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to load progress'
      } finally {
        this.loading = false
      }
    },
    async startStage(stageId) {
      try {
        await productionService.startStage(this.batchId, stageId)
        this.fetchProgress()
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to start stage'
      }
    },
    async completeStage(stageId) {
      try {
        await productionService.completeStage(this.batchId, stageId)
        this.fetchProgress()
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to complete stage'
      }
    },
    canStartStage(index) {
      if (index === 0) return true
      return this.stages[index - 1].status === 'completed'
    },
    getStageIcon(status) {
      switch (status) {
        case 'completed': return '✓'
        case 'in_progress': return '⟳'
        case 'pending': return '○'
        default: return '○'
      }
    },
    formatDuration(minutes) {
      if (!minutes) return ''
      const hours = Math.floor(minutes / 60)
      const mins = minutes % 60
      if (hours > 0) {
        return `${hours}h ${mins}m`
      }
      return `${mins}m`
    }
  }
}
</script>

<style scoped>
.stage-tracker {
  padding: 20px;
  background-color: #f9f9f9;
  border-radius: 8px;
}

.loading,
.error {
  padding: 20px;
  text-align: center;
}

.error {
  color: #d32f2f;
  background-color: #ffebee;
  border-radius: 4px;
}

.stages {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin: 30px 0;
  position: relative;
}

.stage-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex: 1;
  position: relative;
}

.stage-node {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 80px;
  height: 80px;
  border-radius: 50%;
  border: 3px solid #ddd;
  background-color: white;
  margin-bottom: 10px;
  transition: all 0.3s;
}

.stage-node.completed {
  border-color: #4caf50;
  background-color: #e8f5e9;
  color: #2e7d32;
}

.stage-node.in_progress {
  border-color: #1976d2;
  background-color: #e3f2fd;
  color: #1565c0;
  animation: pulse 2s infinite;
}

.stage-node.pending {
  border-color: #bdbdbd;
  background-color: #f5f5f5;
  color: #757575;
}

@keyframes pulse {
  0%, 100% {
    transform: scale(1);
  }
  50% {
    transform: scale(1.05);
  }
}

.stage-icon {
  font-size: 24px;
  font-weight: bold;
}

.stage-name {
  font-size: 12px;
  margin-top: 5px;
  text-align: center;
  max-width: 70px;
}

.stage-connector {
  position: absolute;
  top: 40px;
  left: 50%;
  width: 100%;
  height: 3px;
  background-color: #ddd;
  z-index: -1;
}

.stage-container:last-child .stage-connector {
  display: none;
}

.stage-actions {
  margin-top: 10px;
}

.btn-start,
.btn-complete {
  padding: 6px 12px;
  background-color: #1976d2;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 12px;
}

.btn-complete {
  background-color: #4caf50;
}

.btn-start:hover {
  background-color: #1565c0;
}

.btn-complete:hover {
  background-color: #45a049;
}

.stage-info {
  margin-top: 8px;
  text-align: center;
  color: #666;
  font-size: 11px;
}

.stats {
  display: flex;
  gap: 20px;
  justify-content: center;
  padding: 15px;
  background-color: white;
  border-radius: 4px;
  margin-top: 20px;
}

.stat-item {
  display: flex;
  align-items: center;
  gap: 8px;
}

.stat-label {
  font-weight: 600;
  color: #666;
}

.stat-value {
  color: #1976d2;
  font-weight: bold;
}
</style>
