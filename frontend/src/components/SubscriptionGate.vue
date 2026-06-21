<template>
  <div class="sg-wrap">
    <div class="sg-card">
      <div class="sg-icon">🔒</div>
      <h2>{{ isTrial ? 'Your free trial has ended' : 'Subscription inactive' }}</h2>
      <p class="sg-msg">{{ message }}</p>

      <div v-if="loadErr" class="sg-err">{{ loadErr }}</div>

      <div class="sg-plans">
        <div v-for="p in plans" :key="p.key" class="sg-plan" :class="{ featured: p.key === 'growth' }">
          <div class="sg-plan-name">{{ p.name }}</div>
          <div class="sg-plan-price">₹{{ p.price.toLocaleString('en-IN') }}<span>/mo</span></div>
          <ul>
            <li v-for="f in p.features" :key="f">{{ f }}</li>
          </ul>
          <button v-if="onlineBilling" class="sg-pay" :disabled="paying" @click="pay(p.key)">
            {{ paying === p.key ? 'Opening…' : 'Pay & Activate' }}
          </button>
        </div>
      </div>

      <p v-if="!onlineBilling" class="sg-contact">
        To activate, contact us at <a href="mailto:sales@panelos.app">sales@panelos.app</a>
        or call <b>+91-XXXXXXXXXX</b>. We'll activate your account on payment.
      </p>
      <p v-else class="sg-contact">Secure payment via Razorpay · UPI / Card / Net Banking. Plan activates instantly.</p>

      <div class="sg-actions">
        <button class="btn-ghost" @click="$emit('logout')">Sign out</button>
        <button class="btn-primary" @click="$emit('retry')">{{ onlineBilling ? 'Already paid — Refresh' : "I've renewed — Retry" }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import billingService from '../services/billingService.js'
import { toastSuccess, toastError } from '../services/ui.js'

const props = defineProps({ info: { type: Object, default: () => ({}) } })
const emit = defineEmits(['logout', 'retry'])

const isTrial = computed(() => !!props.info?.data?.trial_ends_at)
const message = computed(() => props.info?.message ?? 'Please choose a plan to continue using PanelOS.')

const onlineBilling = ref(false)
const company = ref(null)
const paying = ref(null)
const loadErr = ref(null)

const fallbackPlans = [
  { key: 'starter', name: 'Starter', price: 2999, features: ['Up to 3 users', 'Quotations & Orders', 'Basic reports'] },
  { key: 'growth',  name: 'Growth',  price: 5999, features: ['Up to 10 users', 'Production + Inventory', 'GST + Tally export', 'WhatsApp alerts'] },
  { key: 'pro',     name: 'Pro',     price: 9999, features: ['Unlimited users', 'e-Invoice / e-Way', 'Advanced MIS', 'Priority support'] },
]
const plans = ref(fallbackPlans)

async function loadStatus() {
  try {
    const r = await billingService.status()
    const d = r?.data ?? r
    onlineBilling.value = !!d.online_billing
    company.value = { name: d.company_name, email: d.company_email }
    if (Array.isArray(d.plans) && d.plans.length) {
      const feat = Object.fromEntries(fallbackPlans.map(p => [p.key, p.features]))
      plans.value = d.plans.filter(p => p.key !== 'enterprise').map(p => ({
        key: p.key, name: p.name, price: p.price, features: feat[p.key] ?? [],
      }))
    }
  } catch (e) { /* status reachable even when expired; ignore */ }
}

async function pay(planKey) {
  paying.value = planKey
  try {
    await billingService.pay(planKey, 1, company.value)
    toastSuccess('Payment successful — welcome back!')
    emit('retry')
  } catch (e) {
    const msg = e?.response?.data?.message ?? e?.message ?? 'Payment failed.'
    if (msg !== 'Payment cancelled.') toastError(msg)
  } finally { paying.value = null }
}

onMounted(loadStatus)
</script>

<style scoped>
.sg-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px;
  background: linear-gradient(135deg, var(--primary) 0%, var(--brand-ink) 100%); }
.sg-card { background: #fff; border-radius: 18px; padding: 40px; max-width: 780px; width: 100%; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.sg-icon { font-size: 44px; margin-bottom: 8px; }
.sg-card h2 { margin: 0 0 8px; font-size: 24px; color: var(--primary); }
.sg-msg { color: #555; font-size: 14px; margin-bottom: 22px; }
.sg-err { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 9px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }

.sg-plans { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 22px; }
.sg-plan { border: 1px solid #e2e6ec; border-radius: 12px; padding: 18px 16px; text-align: left; display: flex; flex-direction: column; }
.sg-plan.featured { border-color: var(--primary); box-shadow: 0 4px 16px rgba(26,35,126,.12); }
.sg-plan-name { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--primary); }
.sg-plan-price { font-size: 26px; font-weight: 800; color: #15181E; margin: 6px 0 10px; }
.sg-plan-price span { font-size: 13px; font-weight: 500; color: #888; }
.sg-plan ul { margin: 0 0 14px; padding-left: 18px; flex: 1; }
.sg-plan li { font-size: 12.5px; color: #444; margin: 4px 0; }
.sg-pay { width: 100%; padding: 9px; background: var(--primary); color: #fff; border: none; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; }
.sg-pay:disabled { opacity: .6; cursor: not-allowed; }

.sg-contact { font-size: 13px; color: #667085; margin-bottom: 20px; }
.sg-contact a { color: var(--primary); font-weight: 600; }
.sg-actions { display: flex; gap: 12px; justify-content: center; }
.btn-ghost { padding: 11px 22px; border: 1px solid #ddd; background: #fff; color: #555; border-radius: 9px; font-size: 14px; font-weight: 600; cursor: pointer; }
.btn-primary { padding: 11px 22px; border: none; background: var(--primary); color: #fff; border-radius: 9px; font-size: 14px; font-weight: 700; cursor: pointer; }

@media (max-width: 640px) { .sg-plans { grid-template-columns: 1fr; } }
</style>
