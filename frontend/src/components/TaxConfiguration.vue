<template>
  <div class="tax-configuration">
    <h2>Tax Configuration</h2>

    <div class="config-form">
      <div class="form-group">
        <label for="gst_number">GST Number</label>
        <input
          id="gst_number"
          v-model="config.gst_number"
          type="text"
          placeholder="e.g., 27AABUT1234K1ZA"
          class="form-input"
        />
        <small>15-character GST registration number (India)</small>
      </div>

      <div class="form-group">
        <label for="tax_type">Tax Type</label>
        <select id="tax_type" v-model="config.tax_type" class="form-input">
          <option value="exclusive">Exclusive (Tax added to amount)</option>
          <option value="inclusive">Inclusive (Tax included in amount)</option>
        </select>
      </div>

      <div class="form-group">
        <label for="default_tax_rate">Default Tax Rate (%)</label>
        <input
          id="default_tax_rate"
          v-model.number="config.default_tax_rate"
          type="number"
          step="0.01"
          min="0"
          max="100"
          class="form-input"
        />
      </div>

      <div class="form-group checkbox">
        <input
          id="is_active"
          v-model="config.is_active"
          type="checkbox"
          class="form-checkbox"
        />
        <label for="is_active">Active</label>
      </div>

      <button @click="saveConfiguration" class="btn-primary">Save Configuration</button>
      <div v-if="message" :class="['message', messageType]">
        {{ message }}
      </div>
    </div>

    <div class="info-section">
      <h3>Tax Breakdown Example</h3>
      <div class="example">
        <p><strong>Amount:</strong> $1,000</p>
        <p><strong>Tax Rate:</strong> 18%</p>
        <p v-if="config.tax_type === 'exclusive'">
          <strong>Total with Exclusive Tax:</strong> $1,180
        </p>
        <p v-else>
          <strong>Total with Inclusive Tax:</strong> $1,000 (tax: $152.54)
        </p>
        <p v-if="config.gst_number">
          <strong>SGST (9%):</strong> $45 | <strong>CGST (9%):</strong> $45
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { taxService } from '@/services/api'

const config = ref({
  gst_number: '',
  tax_type: 'exclusive',
  default_tax_rate: 0,
  is_active: true
})

const message = ref('')
const messageType = ref('')

const fetchConfiguration = async () => {
  try {
    const response = await taxService.getConfiguration()
    if (response) {
      config.value = response
    }
  } catch (error) {
    console.error('Failed to fetch tax configuration:', error)
  }
}

const saveConfiguration = async () => {
  try {
    await taxService.updateConfiguration(config.value)
    message.value = 'Tax configuration saved successfully!'
    messageType.value = 'success'
    setTimeout(() => {
      message.value = ''
    }, 3000)
  } catch (error) {
    message.value = 'Failed to save configuration: ' + error.message
    messageType.value = 'error'
  }
}

onMounted(() => {
  fetchConfiguration()
})
</script>

<style scoped>
.tax-configuration {
  padding: 20px;
  max-width: 800px;
}

.tax-configuration h2 {
  margin-bottom: 20px;
}

.config-form {
  background: white;
  padding: 20px;
  border: 1px solid #ddd;
  border-radius: 4px;
  margin-bottom: 30px;
}

.form-group {
  margin-bottom: 15px;
}

.form-group label {
  display: block;
  margin-bottom: 5px;
  font-weight: 600;
  font-size: 14px;
}

.form-input,
.form-checkbox {
  padding: 8px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
}

.form-input {
  width: 100%;
  box-sizing: border-box;
}

.form-group small {
  display: block;
  margin-top: 3px;
  font-size: 12px;
  color: #999;
}

.form-group.checkbox {
  display: flex;
  align-items: center;
  margin-bottom: 20px;
}

.form-group.checkbox label {
  margin: 0 0 0 8px;
  margin-bottom: 0;
}

.btn-primary {
  background-color: #1976d2;
  color: white;
  padding: 10px 20px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
}

.btn-primary:hover {
  background-color: var(--primary);
}

.message {
  margin-top: 15px;
  padding: 10px;
  border-radius: 4px;
  font-size: 14px;
}

.message.success {
  background-color: #e8f5e9;
  color: #388e3c;
  border: 1px solid #c8e6c9;
}

.message.error {
  background-color: #ffebee;
  color: #d32f2f;
  border: 1px solid #ffcdd2;
}

.info-section {
  background: white;
  padding: 20px;
  border: 1px solid #ddd;
  border-radius: 4px;
}

.info-section h3 {
  margin-bottom: 15px;
}

.example {
  background: #f9f9f9;
  padding: 15px;
  border-radius: 4px;
  font-size: 14px;
}

.example p {
  margin: 8px 0;
}
</style>
