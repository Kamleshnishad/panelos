<template>
  <div class="payment-form">
    <div class="payment-container">
      <h3>Secure Payment</h3>

      <div class="payment-methods">
        <button
          v-for="method in paymentMethods"
          :key="method"
          @click="selectedMethod = method"
          :class="['method-btn', { active: selectedMethod === method }]"
        >
          {{ formatMethodName(method) }}
        </button>
      </div>

      <div v-if="selectedMethod === 'card'" class="card-form">
        <div class="form-group">
          <label>Card Details</label>
          <div id="card-element" class="card-element"></div>
          <div id="card-errors" class="error-message"></div>
        </div>

        <div class="form-group">
          <label>Email</label>
          <input
            v-model="email"
            type="email"
            class="form-input"
            placeholder="customer@example.com"
            required
          />
        </div>

        <button
          @click="processCardPayment"
          :disabled="loading || !stripe || !elements"
          class="btn-primary"
        >
          {{ loading ? 'Processing...' : 'Pay ' + formatCurrency(invoiceAmount) }}
        </button>
      </div>

      <div v-if="selectedMethod === 'link'" class="link-form">
        <p>Generate a secure payment link to share with customers.</p>
        <button
          @click="generatePaymentLink"
          :disabled="loading"
          class="btn-primary"
        >
          {{ loading ? 'Generating...' : 'Generate Payment Link' }}
        </button>

        <div v-if="paymentLink" class="payment-link-result">
          <input
            type="text"
            :value="paymentLink"
            class="form-input"
            readonly
          />
          <button @click="copyToClipboard" class="btn-secondary">
            Copy Link
          </button>
        </div>
      </div>

      <div v-if="selectedMethod === 'checkout'" class="checkout-form">
        <p>Redirect to Stripe Checkout for a full payment experience.</p>
        <button
          @click="redirectToCheckout"
          :disabled="loading"
          class="btn-primary"
        >
          {{ loading ? 'Redirecting...' : 'Open Checkout' }}
        </button>
      </div>

      <div v-if="error" class="error-alert">
        {{ error }}
      </div>

      <div v-if="success" class="success-alert">
        Payment processed successfully!
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { toastSuccess } from '../services/ui.js'
import { loadStripe } from '@stripe/js'
import { paymentService } from '@/services/accountingService'

const props = defineProps({
  invoiceId: {
    type: Number,
    required: true
  },
  invoiceAmount: {
    type: Number,
    required: true
  }
})

const emit = defineEmits(['payment-success', 'payment-error'])

const stripe = ref(null)
const elements = ref(null)
const cardElement = ref(null)
const selectedMethod = ref('card')
const email = ref('')
const loading = ref(false)
const error = ref(null)
const success = ref(false)
const paymentLink = ref(null)
const paymentMethods = ['card', 'link', 'checkout']

const formatMethodName = (method) => {
  const names = {
    card: '💳 Card',
    link: '🔗 Payment Link',
    checkout: '🛒 Stripe Checkout'
  }
  return names[method] || method
}

const formatCurrency = (amount) => {
  return '₹' + amount.toFixed(2)
}

const copyToClipboard = () => {
  navigator.clipboard.writeText(paymentLink.value)
  toastSuccess('Payment link copied to clipboard!')
}

const processCardPayment = async () => {
  if (!stripe.value || !cardElement.value) return

  loading.value = true
  error.value = null

  try {
    const { clientSecret, intentId, amount } = await paymentService.createPaymentIntent(props.invoiceId)

    const { setupIntent, error: confirmError } = await stripe.value.confirmCardPayment(clientSecret, {
      payment_method: {
        card: cardElement.value,
        billing_details: { email: email.value }
      }
    })

    if (confirmError) {
      error.value = confirmError.message
      emit('payment-error', confirmError.message)
      return
    }

    if (setupIntent.status === 'succeeded') {
      success.value = true
      emit('payment-success', { intentId, amount })
      setTimeout(() => {
        window.location.href = `/invoices/${props.invoiceId}?payment=success`
      }, 1500)
    }
  } catch (e) {
    error.value = 'Payment processing failed: ' + e.message
    emit('payment-error', e.message)
  } finally {
    loading.value = false
  }
}

const generatePaymentLink = async () => {
  loading.value = true
  error.value = null

  try {
    const response = await paymentService.getPaymentLink(props.invoiceId)
    if (response.success) {
      paymentLink.value = response.payment_link
      success.value = true
    } else {
      error.value = response.message
    }
  } catch (e) {
    error.value = 'Failed to generate payment link: ' + e.message
  } finally {
    loading.value = false
  }
}

const redirectToCheckout = async () => {
  loading.value = true
  error.value = null

  try {
    const response = await paymentService.createCheckoutSession(props.invoiceId)
    if (response.success && response.payment_url) {
      window.location.href = response.payment_url
    } else {
      error.value = response.message || 'Failed to create checkout session'
    }
  } catch (e) {
    error.value = 'Failed to redirect to checkout: ' + e.message
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  try {
    stripe.value = await loadStripe(import.meta.env.VITE_STRIPE_PUBLIC_KEY)
    elements.value = stripe.value.elements()
    cardElement.value = elements.value.create('card')
    cardElement.value.mount('#card-element')

    cardElement.value.on('change', (event) => {
      const displayError = document.getElementById('card-errors')
      if (event.error) {
        displayError.textContent = event.error.message
      } else {
        displayError.textContent = ''
      }
    })
  } catch (e) {
    error.value = 'Failed to load Stripe: ' + e.message
  }
})
</script>

<style scoped>
.payment-form {
  padding: 20px;
}

.payment-container {
  background: white;
  border: 1px solid #ddd;
  border-radius: 8px;
  padding: 30px;
  max-width: 500px;
}

.payment-container h3 {
  margin-top: 0;
  margin-bottom: 20px;
  font-size: 18px;
}

.payment-methods {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 10px;
  margin-bottom: 20px;
}

.method-btn {
  padding: 10px 12px;
  border: 2px solid #ddd;
  background: white;
  border-radius: 4px;
  cursor: pointer;
  font-size: 12px;
  transition: all 0.2s;
}

.method-btn:hover {
  border-color: #1976d2;
  background: #f5f5f5;
}

.method-btn.active {
  border-color: #1976d2;
  background: var(--primary-tint);
  color: #1976d2;
  font-weight: 600;
}

.card-form,
.link-form,
.checkout-form {
  animation: fadeIn 0.3s ease-in;
}

.form-group {
  margin-bottom: 20px;
}

.form-group label {
  display: block;
  font-weight: 600;
  margin-bottom: 8px;
  color: #333;
}

.form-input {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
  box-sizing: border-box;
}

.form-input:focus {
  outline: none;
  border-color: #1976d2;
  box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
}

.card-element {
  padding: 10px 12px;
  border: 1px solid #ddd;
  border-radius: 4px;
  background: white;
}

.error-message {
  color: #d32f2f;
  font-size: 12px;
  margin-top: 8px;
}

.btn-primary,
.btn-secondary {
  padding: 10px 20px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
  width: 100%;
  transition: all 0.2s;
}

.btn-primary {
  background-color: #1976d2;
  color: white;
  margin-bottom: 10px;
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
  margin-top: 10px;
}

.btn-secondary:hover {
  background-color: #616161;
}

.error-alert {
  background-color: #ffebee;
  border: 1px solid #ef5350;
  color: #c62828;
  padding: 12px;
  border-radius: 4px;
  margin-top: 15px;
}

.success-alert {
  background-color: #e8f5e9;
  border: 1px solid #81c784;
  color: #2e7d32;
  padding: 12px;
  border-radius: 4px;
  margin-top: 15px;
}

.payment-link-result {
  margin-top: 15px;
  padding: 15px;
  background: #f5f5f5;
  border-radius: 4px;
}

.payment-link-result input {
  margin-bottom: 10px;
  padding: 8px;
}

@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(-5px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>
