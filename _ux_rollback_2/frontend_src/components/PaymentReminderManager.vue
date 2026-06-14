<template>
  <div class="reminder-manager">
    <div v-if="reminderStatus" class="reminder-status">
      <h4>📧 Reminder Status</h4>
      <div class="status-details">
        <div class="status-item">
          <span class="label">Type:</span>
          <span class="badge" :class="`type-${reminderStatus.reminder_type}`">
            {{ formatType(reminderStatus.reminder_type) }}
          </span>
        </div>
        <div class="status-item">
          <span class="label">Days Overdue:</span>
          <span class="value">{{ reminderStatus.days_overdue }} days</span>
        </div>
        <div class="status-item">
          <span class="label">Reminders Sent:</span>
          <span class="value">{{ reminderStatus.reminder_count }}</span>
        </div>
        <div class="status-item">
          <span class="label">Last Reminder:</span>
          <span class="value">{{ formatDate(reminderStatus.last_reminded_at) }}</span>
        </div>
        <div class="status-item">
          <span class="label">Next Reminder:</span>
          <span class="value">{{ formatDate(reminderStatus.next_reminder_at) }}</span>
        </div>
      </div>

      <div class="action-buttons">
        <button
          @click="sendManualReminder"
          :disabled="loading"
          class="btn-primary"
        >
          {{ loading ? 'Sending...' : '📨 Send Reminder Now' }}
        </button>
      </div>
    </div>

    <div v-else class="no-reminder">
      <p>No automated reminder scheduled for this invoice</p>
      <button
        @click="scheduleReminder"
        :disabled="loading"
        class="btn-primary"
      >
        {{ loading ? 'Scheduling...' : '📅 Schedule Automatic Reminders' }}
      </button>
    </div>

    <div v-if="message" :class="['alert', messageType]">
      {{ message }}
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { paymentService } from '@/services/accountingService'

const props = defineProps({
  invoiceId: {
    type: Number,
    required: true
  }
})

const reminderStatus = ref(null)
const loading = ref(false)
const message = ref(null)
const messageType = ref('success')

const formatType = (type) => {
  const types = {
    first: '🔔 First Reminder (3 days)',
    second: '🔔 Second Reminder (7 days)',
    final: '⚠️ Final Reminder (14+ days)'
  }
  return types[type] || type
}

const formatDate = (date) => {
  if (!date) return 'Not yet'
  return new Date(date).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

const fetchReminderStatus = async () => {
  try {
    const response = await paymentService.getReminderStatus(props.invoiceId)
    if (response.success && response.data) {
      reminderStatus.value = response.data
    }
  } catch (e) {
    // Reminder may not exist yet
    reminderStatus.value = null
  }
}

const scheduleReminder = async () => {
  loading.value = true
  message.value = null

  try {
    const response = await paymentService.scheduleReminder(props.invoiceId)
    if (response.success) {
      message.value = '✅ Reminders scheduled successfully!'
      messageType.value = 'success'
      await fetchReminderStatus()
    } else {
      message.value = response.message || 'Failed to schedule reminder'
      messageType.value = 'error'
    }
  } catch (e) {
    message.value = 'Error: ' + e.message
    messageType.value = 'error'
  } finally {
    loading.value = false
  }
}

const sendManualReminder = async () => {
  loading.value = true
  message.value = null

  try {
    const response = await paymentService.sendManualReminder(props.invoiceId)
    if (response.success) {
      message.value = '✅ Reminder sent to customer!'
      messageType.value = 'success'
      await fetchReminderStatus()
    } else {
      message.value = response.message || 'Failed to send reminder'
      messageType.value = 'error'
    }
  } catch (e) {
    message.value = 'Error: ' + e.message
    messageType.value = 'error'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchReminderStatus()
})
</script>

<style scoped>
.reminder-manager {
  padding: 15px;
  background: white;
  border: 1px solid #ddd;
  border-radius: 4px;
  margin-top: 15px;
}

.reminder-manager h4 {
  margin: 0 0 15px 0;
  font-size: 16px;
}

.status-details {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 15px;
  margin-bottom: 15px;
}

.status-item {
  display: flex;
  justify-content: space-between;
  padding: 8px;
  background: #f9f9f9;
  border-radius: 4px;
}

.status-item .label {
  font-weight: 600;
  color: #333;
}

.status-item .value,
.status-item .badge {
  color: #666;
}

.badge {
  padding: 4px 8px;
  border-radius: 3px;
  font-size: 12px;
  font-weight: 600;
}

.type-first {
  background-color: var(--primary-tint);
  color: #1976d2;
}

.type-second {
  background-color: #fff3e0;
  color: #f57c00;
}

.type-final {
  background-color: #ffebee;
  color: #d32f2f;
}

.action-buttons {
  display: flex;
  gap: 10px;
}

.btn-primary {
  flex: 1;
  padding: 10px 16px;
  background-color: #1976d2;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
  transition: all 0.2s;
}

.btn-primary:hover:not(:disabled) {
  background-color: var(--primary);
}

.btn-primary:disabled {
  background-color: #bdbdbd;
  cursor: not-allowed;
}

.no-reminder {
  text-align: center;
  padding: 20px;
}

.no-reminder p {
  color: #999;
  margin-bottom: 15px;
}

.no-reminder .btn-primary {
  max-width: 250px;
  margin: 0 auto;
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

@media (max-width: 600px) {
  .status-details {
    grid-template-columns: 1fr;
  }

  .action-buttons {
    flex-direction: column;
  }
}
</style>
