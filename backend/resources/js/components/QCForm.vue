<template>
  <div class="qc-form">
    <h2>Quality Control Entry</h2>

    <form @submit.prevent="submitQC">
      <div class="form-group">
        <label for="status">QC Result *</label>
        <select id="status" v-model="form.status" required>
          <option value="">-- Select Result --</option>
          <option value="pass">Pass</option>
          <option value="fail">Fail</option>
        </select>
      </div>

      <div class="form-group">
        <label for="notes">Notes</label>
        <textarea id="notes" v-model="form.notes" placeholder="Enter any notes about QC inspection..."></textarea>
      </div>

      <div v-if="form.status === 'fail'" class="defects-section">
        <h3>Defects</h3>
        <div v-for="(defect, index) in form.defects" :key="index" class="defect-item">
          <div class="form-group">
            <label>Defect Type</label>
            <select v-model="defect.type">
              <option value="cosmetic">Cosmetic</option>
              <option value="structural">Structural</option>
              <option value="dimension">Dimension</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="form-group">
            <label>Severity</label>
            <select v-model="defect.severity">
              <option value="minor">Minor</option>
              <option value="major">Major</option>
              <option value="critical">Critical</option>
            </select>
          </div>
          <div class="form-group">
            <label>Description</label>
            <input v-model="defect.description" type="text" placeholder="Describe the defect...">
          </div>
          <button type="button" @click="removeDefect(index)" class="btn-remove">Remove</button>
        </div>
        <button type="button" @click="addDefect" class="btn-secondary">+ Add Defect</button>
      </div>

      <div v-if="error" class="error">{{ error }}</div>
      <div v-if="success" class="success">{{ success }}</div>

      <div class="form-actions">
        <button type="submit" class="btn-primary">Submit QC</button>
        <button type="button" @click="resetForm" class="btn-secondary">Clear</button>
      </div>
    </form>
  </div>
</template>

<script>
import productionService from '../services/productionService'

export default {
  name: 'QCForm',
  props: {
    batchId: {
      type: Number,
      required: true
    }
  },
  data() {
    return {
      form: {
        status: '',
        notes: '',
        defects: []
      },
      error: null,
      success: null,
      submitting: false
    }
  },
  methods: {
    async submitQC() {
      try {
        this.error = null
        this.success = null
        this.submitting = true

        const data = {
          status: this.form.status,
          notes: this.form.notes
        }

        if (this.form.status === 'fail' && this.form.defects.length > 0) {
          data.defects = this.form.defects
        }

        await productionService.createQC(this.batchId, data)
        this.success = 'QC entry submitted successfully'
        this.resetForm()
        this.$emit('qc-submitted')
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to submit QC'
      } finally {
        this.submitting = false
      }
    },
    addDefect() {
      this.form.defects.push({
        type: 'cosmetic',
        severity: 'minor',
        description: ''
      })
    },
    removeDefect(index) {
      this.form.defects.splice(index, 1)
    },
    resetForm() {
      this.form = {
        status: '',
        notes: '',
        defects: []
      }
      this.error = null
      this.success = null
    }
  }
}
</script>

<style scoped>
.qc-form {
  padding: 20px;
  background-color: #f9f9f9;
  border-radius: 8px;
  max-width: 600px;
  margin: 0 auto;
}

form {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.form-group {
  display: flex;
  flex-direction: column;
}

label {
  font-weight: 600;
  margin-bottom: 8px;
  color: #333;
}

input,
textarea,
select {
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
  font-family: inherit;
}

input:focus,
textarea:focus,
select:focus {
  outline: none;
  border-color: #1976d2;
  box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
}

textarea {
  resize: vertical;
  min-height: 100px;
}

.defects-section {
  padding: 15px;
  background-color: white;
  border-radius: 4px;
  border-left: 4px solid #d32f2f;
}

.defect-item {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr auto;
  gap: 10px;
  padding: 10px;
  background-color: #fff5f5;
  border-radius: 4px;
  margin-bottom: 10px;
  align-items: flex-end;
}

.defect-item .form-group {
  margin: 0;
}

.btn-remove {
  padding: 8px 12px;
  background-color: #d32f2f;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 12px;
}

.btn-remove:hover {
  background-color: #b71c1c;
}

.error,
.success {
  padding: 12px;
  border-radius: 4px;
  text-align: center;
  font-size: 14px;
}

.error {
  background-color: #ffebee;
  color: #d32f2f;
  border: 1px solid #ef5350;
}

.success {
  background-color: #e8f5e9;
  color: #2e7d32;
  border: 1px solid #4caf50;
}

.form-actions {
  display: flex;
  gap: 10px;
  justify-content: center;
}

.btn-primary,
.btn-secondary {
  padding: 10px 20px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
}

.btn-primary {
  background-color: #1976d2;
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background-color: #1565c0;
}

.btn-primary:disabled {
  background-color: #ccc;
  cursor: not-allowed;
}

.btn-secondary {
  background-color: #e0e0e0;
  color: #333;
}

.btn-secondary:hover {
  background-color: #bdbdbd;
}
</style>
