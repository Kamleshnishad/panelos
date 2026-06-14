<template>
  <div class="payment-tracker">
    <h3>Payment Tracking</h3>

    <div class="status-display" v-if="paymentStatus">
      <div class="status-card">
        <div class="status-label">Total Amount</div>
        <div class="status-value">${{ formatNumber(paymentStatus.total_amount) }}</div>
      </div>
      <div class="status-card">
        <div class="status-label">Paid Amount</div>
        <div class="status-value paid">${{ formatNumber(paymentStatus.paid_amount) }}</div>
      </div>
      <div class="status-card">
        <div class="status-label">Remaining Due</div>
        <div class="status-value remaining">${{ formatNumber(paymentStatus.remaining_due) }}</div>
      </div>
      <div class="status-card">
        <div class="status-label">Payment %</div>
        <div class="status-value">{{ paymentStatus.payment_percentage }}%</div>
      </div>
    </div>

    <div class="progress-bar" v-if="paymentStatus">
      <div class="progress-fill" :style="{ width: paymentStatus.payment_percentage + '%' }"></div>
    </div>

    <div class="payment-form" v-if="paymentStatus && paymentStatus.remaining_due > 0">
      <h4>Record Payment</h4>
      <form @submit.prevent="recordPayment">
        <div class="form-group">
          <label for="amount">Payment Amount</label>
          <input
            id="amount"
            v-model.number="newPayment.amount"
            type="number"
            step="0.01"
            :max="paymentStatus.remaining_due"
            class="form-input"
            required
          />
        </div>
        <div class="form-group">
          <label for="method">Payment Method</label>
          <select id="method" v-model="newPayment.payment_method" class="form-input">
            <option value="bank_transfer">Bank Transfer</option>
            <option value="cash">Cash</option>
            <option value="cheque">Cheque</option>
            <option value="upi">UPI</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="form-group">
          <label for="reference">Reference Number</label>
          <input
            id="reference"
            v-model="newPayment.reference_no"
            type="text"
            class="form-input"
            placeholder="e.g., Check #12345"
          />
        </div>
        <button type="submit" class="btn-primary">Record Payment</button>
      </form>
    </div>

    <div class="payment-history">
      <h4>Payment History</h4>
      <div v-if="paymentHistory.length > 0" class="history-list">
        <div v-for="payment in paymentHistory" :key="payment.id" class="history-item">
          <div class="item-date">{{ formatDate(payment.transaction_date) }}</div>
          <div class="item-method">{{ payment.payment_method }}</div>
          <div class="item-amount">${{ formatNumber(payment.amount) }}</div>
          <div class="item-reference" v-if="payment.reference_no">{{ payment.reference_no }}</div>
        </div>
      </div>
      <div v-else class="empty">No payments recorded yet</div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { paymentService } from '@/services/api'

const props = defineProps({
  invoiceId: {
    type: [String, Number],
    required: true
  }
})

const paymentStatus = ref(null)
const paymentHistory = ref([])
const newPayment = ref({
  amount: 0,
  payment_method: 'bank_transfer',
  reference_no: ''
})

const fetchPaymentStatus = async () => {
  try {
    paymentStatus.value = await paymentService.getPaymentStatus(props.invoiceId)
  } catch (error) {
    console.error('Failed to fetch payment status:', error)
  }
}

const fetchPaymentHistory = async () => {
  try {
    const response = await paymentService.getPaymentHistory(props.invoiceId)
    paymentHistory.value = response || []
  } catch (error) {
    console.error('Failed to fetch payment history:', error)
  }
}

const recordPayment = async () => {
  try {
    await paymentService.recordPayment(props.invoiceId, {
      amount: newPayment.value.amount,
      payment_method: newPayment.value.payment_method,
      reference_no: newPayment.value.reference_no
    })
    await fetchPaymentStatus()
    await fetchPaymentHistory()
    newPayment.value = {
      amount: 0,
      payment_method: 'bank_transfer',
      reference_no: ''
    }
  } catch (error) {
    console.error('Failed to record payment:', error)
  }
}

const formatNumber = (num) => num?.toFixed(2) || '0.00'
const formatDate = (date) => new Date(date).toLocaleDateString()

onMounted(() => {
  fetchPaymentStatus()
  fetchPaymentHistory()
})
</script>

<style scoped>
.payment-tracker {
  grid-column: 1 / -1;
  background: white;
  padding: 20px;
  border: 1px solid #ddd;
  border-radius: 4px;
}

.payment-tracker h3 {
  margin-bottom: 20px;
}

.payment-tracker h4 {
  margin-bottom: 15px;
  font-size: 14px;
}

.status-display {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 15px;
  margin-bottom: 20px;
}

.status-card {
  background: #f9f9f9;
  padding: 15px;
  border-radius: 4px;
  border: 1px solid #e0e0e0;
}

.status-label {
  font-size: 12px;
  color: #666;
  margin-bottom: 5px;
}

.status-value {
  font-size: 18px;
  font-weight: bold;
  color: #333;
}

.status-value.paid {
  color: #388e3c;
}

.status-value.remaining {
  color: #d32f2f;
}

.progress-bar {
  height: 20px;
  background: #e0e0e0;
  border-radius: 4px;
  overflow: hidden;
  margin-bottom: 20px;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #1976d2, #388e3c);
  transition: width 0.3s ease;
}

.payment-form {
  background: #f9f9f9;
  padding: 15px;
  border-radius: 4px;
  margin-bottom: 20px;
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

.form-input {
  width: 100%;
  padding: 8px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
}

.btn-primary {
  background-color: #1976d2;
  color: white;
  padding: 8px 16px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
}

.btn-primary:hover {
  background-color: var(--primary);
}

.payment-history {
  margin-top: 20px;
}

.history-list {
  border: 1px solid #e0e0e0;
  border-radius: 4px;
  overflow: hidden;
}

.history-item {
  display: grid;
  grid-template-columns: 150px 150px 150px 1fr;
  gap: 15px;
  padding: 12px;
  border-bottom: 1px solid #e0e0e0;
  align-items: center;
}

.history-item:last-child {
  border-bottom: none;
}

.item-date {
  font-size: 14px;
  color: #666;
}

.item-method {
  font-size: 14px;
  font-weight: 600;
}

.item-amount {
  font-size: 14px;
  font-weight: bold;
  color: #388e3c;
}

.item-reference {
  font-size: 12px;
  color: #999;
}

.empty {
  padding: 20px;
  text-align: center;
  color: #999;
}

@media (max-width: 768px) {
  .status-display {
    grid-template-columns: repeat(2, 1fr);
  }

  .history-item {
    grid-template-columns: 1fr;
  }
}
</style>
