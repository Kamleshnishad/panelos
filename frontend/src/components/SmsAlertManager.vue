<template>
  <div class="sms-alert-manager">
    <div class="sms-header">
      <h3>📱 SMS Alerts & Reminders</h3>
      <div class="status-badge" :class="{ enabled: smsEnabled, disabled: !smsEnabled }">
        {{ smsEnabled ? '✓ Enabled' : '✗ Disabled' }}
      </div>
    </div>

    <div v-if="smsEnabled" class="sms-controls">
      <div class="control-section">
        <h4>Send SMS Reminder</h4>
        <button
          @click="sendSmsReminder"
          :disabled="loading"
          class="btn-primary"
        >
          {{ loading ? 'Sending...' : '📨 Send SMS Reminder' }}
        </button>
      </div>

      <div class="control-section">
        <h4>Send Custom SMS</h4>
        <div class="form-group">
          <label>Phone Number</label>
          <input
            v-model="customSms.phoneNumber"
            type="tel"
            class="form-input"
            placeholder="+91 98765 43210"
          />
          <button
            @click="validatePhone"
            class="btn-secondary"
            style="margin-top: 8px"
          >
            Validate
          </button>
          <div v-if="phoneValidation" :class="['validation-result', phoneValidation.valid ? 'valid' : 'invalid']">
            {{ phoneValidation.message }}
          </div>
        </div>

        <div class="form-group">
          <label>Message (Max 160 characters)</label>
          <textarea
            v-model="customSms.message"
            class="form-input"
            :maxlength="160"
            placeholder="Enter your message"
          ></textarea>
          <div class="char-count">{{ customSms.message.length }}/160</div>
        </div>

        <button
          @click="sendCustomSms"
          :disabled="loading || !phoneValidation?.valid"
          class="btn-primary"
        >
          {{ loading ? 'Sending...' : '📤 Send SMS' }}
        </button>
      </div>

      <div class="control-section">
        <h4>Recent SMS Logs</h4>
        <button
          @click="fetchSmsLogs"
          :disabled="loadingLogs"
          class="btn-secondary"
        >
          {{ loadingLogs ? 'Loading...' : 'Refresh Logs' }}
        </button>

        <div v-if="smsLogs.length > 0" class="sms-logs">
          <div v-for="log in smsLogs" :key="log.id" class="log-entry" :class="{ success: log.success, failed: !log.success }">
            <div class="log-header">
              <span class="type-badge">{{ formatType(log.type) }}</span>
              <span class="status-badge" :class="{ success: log.success, failed: !log.success }">
                {{ log.success ? '✓ Sent' : '✗ Failed' }}
              </span>
              <span class="time">{{ formatDate(log.created_at) }}</span>
            </div>
            <div class="log-message">{{ log.message }}</div>
            <div v-if="log.error_message" class="log-error">
              Error: {{ log.error_message }}
            </div>
          </div>
        </div>
        <div v-else class="no-logs">No SMS logs available</div>
      </div>
    </div>

    <div v-else class="sms-disabled">
      <p>SMS service is not enabled. Please configure Twilio credentials to enable SMS alerts.</p>
      <p>Update your server configuration with Twilio API keys to send SMS notifications.</p>
    </div>

    <div v-if="message" :class="['alert', messageType]">
      {{ message }}
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { smsService } from '@/services/accountingService'

const props = defineProps({
  invoiceId: {
    type: Number,
    required: true
  }
})

const smsEnabled = ref(false)
const loading = ref(false)
const loadingLogs = ref(false)
const message = ref(null)
const messageType = ref('success')
const phoneValidation = ref(null)
const smsLogs = ref([])

const customSms = ref({
  phoneNumber: '',
  message: ''
})

const formatType = (type) => {
  const types = {
    payment_reminder: '💰 Payment Reminder',
    low_stock_alert: '⚠️ Low Stock Alert',
    production_alert: '📦 Production Alert',
    custom_message: '📬 Custom Message'
  }
  return types[type] || type
}

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

const validatePhone = async () => {
  if (!customSms.value.phoneNumber) {
    phoneValidation.value = { valid: false, message: 'Please enter a phone number' }
    return
  }

  try {
    const response = await smsService.validatePhoneNumber(customSms.value.phoneNumber)
    phoneValidation.value = {
      valid: response.success,
      message: response.message
    }
  } catch (e) {
    phoneValidation.value = {
      valid: false,
      message: 'Validation failed: ' + e.message
    }
  }
}

const sendSmsReminder = async () => {
  loading.value = true
  message.value = null

  try {
    const response = await smsService.sendPaymentReminderSms(props.invoiceId)
    if (response.success) {
      message.value = '✅ SMS reminder sent successfully!'
      messageType.value = 'success'
      await fetchSmsLogs()
    } else {
      message.value = response.message || 'Failed to send SMS'
      messageType.value = 'error'
    }
  } catch (e) {
    message.value = 'Error: ' + e.message
    messageType.value = 'error'
  } finally {
    loading.value = false
  }
}

const sendCustomSms = async () => {
  loading.value = true
  message.value = null

  try {
    const response = await smsService.sendCustomSms(
      customSms.value.phoneNumber,
      customSms.value.message
    )

    if (response.success) {
      message.value = '✅ SMS sent successfully!'
      messageType.value = 'success'
      customSms.value.phoneNumber = ''
      customSms.value.message = ''
      phoneValidation.value = null
      await fetchSmsLogs()
    } else {
      message.value = response.message || 'Failed to send SMS'
      messageType.value = 'error'
    }
  } catch (e) {
    message.value = 'Error: ' + e.message
    messageType.value = 'error'
  } finally {
    loading.value = false
  }
}

const fetchSmsLogs = async () => {
  loadingLogs.value = true

  try {
    const response = await smsService.getSmsLogs()
    if (response.success) {
      smsLogs.value = response.data || []
    }
  } catch (e) {
    console.error('Failed to fetch SMS logs:', e)
  } finally {
    loadingLogs.value = false
  }
}

const checkSmsStatus = async () => {
  try {
    const response = await smsService.getSmsStatus()
    smsEnabled.value = response.enabled || false
  } catch (e) {
    smsEnabled.value = false
  }
}

onMounted(() => {
  checkSmsStatus()
  fetchSmsLogs()
})
</script>

<style scoped>
.sms-alert-manager {
  padding: 20px;
  background: white;
  border: 1px solid #ddd;
  border-radius: 8px;
  margin: 20px 0;
}

.sms-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  padding-bottom: 15px;
  border-bottom: 2px solid #f0f0f0;
}

.sms-header h3 {
  margin: 0;
  font-size: 18px;
}

.status-badge {
  padding: 6px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
}

.status-badge.enabled {
  background-color: #e8f5e9;
  color: #2e7d32;
}

.status-badge.disabled {
  background-color: #ffebee;
  color: #c62828;
}

.sms-controls {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  margin-bottom: 20px;
}

.control-section {
  background: #f9f9f9;
  padding: 15px;
  border-radius: 4px;
}

.control-section h4 {
  margin: 0 0 12px 0;
  font-size: 14px;
  color: #333;
}

.form-group {
  margin-bottom: 15px;
}

.form-group label {
  display: block;
  font-weight: 600;
  margin-bottom: 6px;
  font-size: 13px;
}

.form-input {
  width: 100%;
  padding: 8px 10px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 13px;
  box-sizing: border-box;
}

.form-input:focus {
  outline: none;
  border-color: #1976d2;
  box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
}

textarea.form-input {
  resize: vertical;
  min-height: 80px;
  font-family: Arial, sans-serif;
}

.char-count {
  font-size: 12px;
  color: #999;
  margin-top: 4px;
  text-align: right;
}

.validation-result {
  margin-top: 8px;
  padding: 8px;
  border-radius: 3px;
  font-size: 12px;
}

.validation-result.valid {
  background-color: #e8f5e9;
  color: #2e7d32;
  border: 1px solid #81c784;
}

.validation-result.invalid {
  background-color: #ffebee;
  color: #c62828;
  border: 1px solid #ef5350;
}

.btn-primary,
.btn-secondary {
  padding: 8px 16px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  width: 100%;
  transition: all 0.2s;
}

.btn-primary {
  background-color: #1976d2;
  color: white;
  margin-top: 10px;
}

.btn-primary:hover:not(:disabled) {
  background-color: var(--primary);
}

.btn-primary:disabled {
  background-color: #bdbdbd;
  cursor: not-allowed;
}

.btn-secondary {
  background-color: #757575;
  color: white;
}

.btn-secondary:hover:not(:disabled) {
  background-color: #616161;
}

.sms-logs {
  display: grid;
  gap: 10px;
  margin-top: 12px;
}

.log-entry {
  padding: 12px;
  border-left: 4px solid #ddd;
  background: white;
  border-radius: 3px;
  font-size: 12px;
}

.log-entry.success {
  border-left-color: #81c784;
  background: #f1f8e9;
}

.log-entry.failed {
  border-left-color: #ef5350;
  background: #ffebee;
}

.log-header {
  display: flex;
  gap: 10px;
  margin-bottom: 6px;
  align-items: center;
}

.type-badge {
  padding: 2px 8px;
  background: #e0e0e0;
  border-radius: 3px;
  font-weight: 600;
}

.status-badge {
  padding: 2px 8px;
  border-radius: 3px;
  font-weight: 600;
}

.status-badge.success {
  background: #81c784;
  color: white;
}

.status-badge.failed {
  background: #ef5350;
  color: white;
}

.time {
  margin-left: auto;
  color: #999;
}

.log-message {
  color: #333;
  margin: 6px 0;
}

.log-error {
  color: #c62828;
  font-style: italic;
  margin-top: 6px;
}

.no-logs {
  padding: 15px;
  text-align: center;
  color: #999;
}

.sms-disabled {
  padding: 20px;
  background: #fff3cd;
  border: 1px solid #ffc107;
  border-radius: 4px;
  color: #856404;
}

.sms-disabled p {
  margin: 10px 0;
}

.alert {
  padding: 12px;
  border-radius: 4px;
  margin-top: 15px;
  font-size: 14px;
}

.alert.success {
  background-color: #e8f5e9;
  border: 1px solid #81c784;
  color: #2e7d32;
}

.alert.error {
  background-color: #ffebee;
  border: 1px solid #ef5350;
  color: #c62828;
}

@media (max-width: 768px) {
  .sms-controls {
    grid-template-columns: 1fr;
  }

  .sms-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }
}
</style>
