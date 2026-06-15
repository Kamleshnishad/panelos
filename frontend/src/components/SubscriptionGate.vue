<template>
  <div class="sg-wrap">
    <div class="sg-card">
      <div class="sg-icon">🔒</div>
      <h2>{{ isTrial ? 'Your free trial has ended' : 'Subscription inactive' }}</h2>
      <p class="sg-msg">{{ message }}</p>

      <div class="sg-plans">
        <div v-for="p in plans" :key="p.key" class="sg-plan">
          <div class="sg-plan-name">{{ p.name }}</div>
          <div class="sg-plan-price">₹{{ p.price.toLocaleString('en-IN') }}<span>/mo</span></div>
          <ul>
            <li v-for="f in p.features" :key="f">{{ f }}</li>
          </ul>
        </div>
      </div>

      <p class="sg-contact">
        To activate, contact us at <a href="mailto:sales@panelos.app">sales@panelos.app</a>
        or call <b>+91-XXXXXXXXXX</b>. Online payment coming soon.
      </p>

      <div class="sg-actions">
        <button class="btn-ghost" @click="$emit('logout')">Sign out</button>
        <button class="btn-primary" @click="$emit('retry')">I've renewed — Retry</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({ info: { type: Object, default: () => ({}) } })
defineEmits(['logout', 'retry'])

const isTrial = computed(() => !!props.info?.data?.trial_ends_at)
const message = computed(() => props.info?.message ?? 'Please choose a plan to continue using PanelOS.')

// Static preview of plans (real pricing/billing arrives with Razorpay integration)
const plans = [
  { key: 'starter', name: 'Starter', price: 2999, features: ['Up to 3 users', 'Quotations & Orders', 'Basic reports'] },
  { key: 'growth',  name: 'Growth',  price: 5999, features: ['Up to 10 users', 'Production + Inventory', 'GST + Tally export', 'WhatsApp alerts'] },
  { key: 'pro',     name: 'Pro',     price: 9999, features: ['Unlimited users', 'e-Invoice / e-Way', 'Advanced MIS', 'Priority support'] },
]
</script>

<style scoped>
.sg-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px;
  background: linear-gradient(135deg, #1a237e 0%, #0d1333 100%); }
.sg-card { background: #fff; border-radius: 18px; padding: 40px; max-width: 760px; width: 100%; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.sg-icon { font-size: 44px; margin-bottom: 8px; }
.sg-card h2 { margin: 0 0 8px; font-size: 24px; color: #1a237e; }
.sg-msg { color: #555; font-size: 14px; margin-bottom: 26px; }

.sg-plans { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 24px; }
.sg-plan { border: 1px solid #e2e6ec; border-radius: 12px; padding: 18px 16px; text-align: left; }
.sg-plan:nth-child(2) { border-color: #1a237e; box-shadow: 0 4px 16px rgba(26,35,126,.12); }
.sg-plan-name { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: #1a237e; }
.sg-plan-price { font-size: 26px; font-weight: 800; color: #15181E; margin: 6px 0 10px; }
.sg-plan-price span { font-size: 13px; font-weight: 500; color: #888; }
.sg-plan ul { margin: 0; padding-left: 18px; }
.sg-plan li { font-size: 12.5px; color: #444; margin: 4px 0; }

.sg-contact { font-size: 13px; color: #667085; margin-bottom: 22px; }
.sg-contact a { color: #1a237e; font-weight: 600; }
.sg-actions { display: flex; gap: 12px; justify-content: center; }
.btn-ghost { padding: 11px 22px; border: 1px solid #ddd; background: #fff; color: #555; border-radius: 9px; font-size: 14px; font-weight: 600; cursor: pointer; }
.btn-primary { padding: 11px 22px; border: none; background: #1a237e; color: #fff; border-radius: 9px; font-size: 14px; font-weight: 700; cursor: pointer; }

@media (max-width: 640px) { .sg-plans { grid-template-columns: 1fr; } }
</style>
