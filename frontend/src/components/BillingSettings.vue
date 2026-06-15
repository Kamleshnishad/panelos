<template>
  <div class="bl-wrap">
    <div class="bl-header">
      <div>
        <h2>Billing &amp; Subscription</h2>
        <p class="bl-sub">Your plan, renewal and upgrades.</p>
      </div>
      <button class="btn btn-ghost" :disabled="loading" @click="load">↻</button>
    </div>

    <div v-if="error" class="error-banner">{{ error }}</div>
    <div v-if="loading" class="loading-hint">Loading…</div>

    <template v-else-if="status">
      <!-- Current plan -->
      <div class="current-card" :class="status.subscription_status">
        <div>
          <div class="cur-label">Current Plan</div>
          <div class="cur-plan">{{ (status.current_plan || 'trial') }}</div>
        </div>
        <div class="cur-meta">
          <span class="status-chip" :class="status.subscription_status">{{ status.subscription_status }}</span>
          <div class="cur-end" v-if="status.subscription_status === 'trial' && status.trial_ends_at">
            Trial ends {{ fmtDate(status.trial_ends_at) }}
          </div>
          <div class="cur-end" v-else-if="status.subscription_ends_at">
            Renews / expires {{ fmtDate(status.subscription_ends_at) }}
          </div>
        </div>
      </div>

      <div v-if="!status.online_billing" class="notice">
        Online payment isn't configured yet. To upgrade or renew, contact
        <a href="mailto:sales@panelos.app">sales@panelos.app</a>.
      </div>

      <!-- Coupon -->
      <div v-if="status.online_billing" class="coupon-bar">
        <span class="coupon-lbl">🎟️ Have a promo code?</span>
        <input v-model="coupon" placeholder="Enter code" class="coupon-input" />
      </div>

      <!-- Plans -->
      <div class="plans">
        <div v-for="p in status.plans" :key="p.key" class="plan" :class="{ current: p.key === status.current_plan, featured: p.key === 'growth' }">
          <div class="plan-name">{{ p.name }}</div>
          <div class="plan-price">₹{{ Number(p.price).toLocaleString('en-IN') }}<span>/mo</span></div>
          <ul class="plan-limits">
            <li>{{ p.limits.users ? p.limits.users + ' users' : 'Unlimited users' }}</li>
            <li>{{ p.limits.einvoice ? 'e-Invoice / e-Way' : 'No e-Invoice' }}</li>
          </ul>
          <button v-if="status.online_billing" class="plan-btn" :disabled="paying" @click="pay(p.key)">
            {{ paying === p.key ? 'Opening…' : (p.key === status.current_plan ? 'Renew' : 'Choose') }}
          </button>
          <span v-else-if="p.key === status.current_plan" class="cur-tag">Current</span>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import billingService from '../services/billingService.js'
import { toastSuccess, toastError } from '../services/ui.js'

const status = ref(null)
const loading = ref(false)
const error = ref(null)
const paying = ref(null)
const coupon = ref('')

function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—' }

async function load() {
  loading.value = true; error.value = null
  try {
    const r = await billingService.status()
    status.value = r?.data ?? r
  } catch (e) { error.value = e?.response?.data?.message ?? 'Failed to load billing.' }
  finally { loading.value = false }
}

async function pay(planKey) {
  paying.value = planKey
  try {
    await billingService.pay(planKey, 1, { name: status.value.company_name, email: status.value.company_email }, coupon.value || null)
    toastSuccess('Payment successful — plan updated!')
    await load()
  } catch (e) {
    const msg = e?.response?.data?.message ?? e?.message ?? 'Payment failed.'
    if (msg !== 'Payment cancelled.') toastError(msg)
  } finally { paying.value = null }
}

onMounted(load)
</script>

<style scoped>
.bl-wrap { padding: 24px 32px 48px; max-width: 1000px; margin: 0 auto; }
.bl-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 18px; }
.bl-header h2 { margin: 0; font-size: 22px; color: var(--primary); }
.bl-sub { margin: 4px 0 0; font-size: 13px; color: var(--text-2); }
.error-banner { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }
.loading-hint { text-align: center; padding: 40px; color: #999; }

.current-card { display: flex; align-items: center; justify-content: space-between; background: #fff; border: 1px solid #e0e0e0; border-left: 4px solid var(--primary); border-radius: 12px; padding: 18px 22px; margin-bottom: 16px; }
.current-card.expired { border-left-color: #c62828; }
.current-card.trial { border-left-color: #b5740a; }
.current-card.active { border-left-color: #2e7d32; }
.cur-label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #888; font-weight: 700; }
.cur-plan { font-size: 22px; font-weight: 800; color: var(--primary); text-transform: capitalize; }
.cur-meta { text-align: right; }
.cur-end { font-size: 12px; color: #667085; margin-top: 6px; }
.status-chip { font-size: 10px; font-weight: 700; padding: 3px 10px; border-radius: 10px; text-transform: uppercase; }
.status-chip.trial { background: #fff8e1; color: #b5740a; }
.status-chip.active { background: #e8f5e9; color: #2e7d32; }
.status-chip.expired { background: #ffebee; color: #c62828; }

.notice { background: #fff8e1; border: 1px solid #ffe082; color: #6d4c00; padding: 11px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
.notice a { color: var(--primary); font-weight: 600; }

.coupon-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; background: #f8faff; border: 1px solid #dce6f8; border-radius: 8px; padding: 10px 14px; }
.coupon-lbl { font-size: 13px; color: var(--text-2); font-weight: 600; }
.coupon-input { padding: 7px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; text-transform: uppercase; }
.plans { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.plan { border: 1px solid #e2e6ec; border-radius: 12px; padding: 18px 16px; display: flex; flex-direction: column; }
.plan.featured { border-color: var(--primary); }
.plan.current { background: #f8faff; }
.plan-name { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--primary); }
.plan-price { font-size: 24px; font-weight: 800; color: #15181E; margin: 6px 0 10px; }
.plan-price span { font-size: 12px; font-weight: 500; color: #888; }
.plan-limits { margin: 0 0 14px; padding-left: 18px; flex: 1; }
.plan-limits li { font-size: 12.5px; color: #444; margin: 4px 0; }
.plan-btn { width: 100%; padding: 9px; background: var(--primary); color: #fff; border: none; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; }
.plan-btn:disabled { opacity: .6; }
.cur-tag { text-align: center; font-size: 12px; font-weight: 700; color: #2e7d32; }

@media (max-width: 760px) { .plans { grid-template-columns: 1fr; } }
</style>
