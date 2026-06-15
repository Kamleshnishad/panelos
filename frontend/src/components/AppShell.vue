<template>
  <div class="shell">
    <!-- Mobile overlay -->
    <div v-if="mobileOpen" class="sidebar-scrim" @click="mobileOpen = false"></div>

    <!-- Sidebar -->
    <aside class="sidebar" :class="{ open: mobileOpen }">
      <div class="side-brand" @click="go('dashboard')">
        <div class="brand-mark">
          <img v-if="companyLogo" :src="companyLogo" class="brand-logo-img" alt="logo" />
          <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="5" stroke="#fff" stroke-width="2.2"/><path d="M8 8h5a3 3 0 0 1 0 6H8z" stroke="#fff" stroke-width="2.2" stroke-linejoin="round"/></svg>
        </div>
        <div class="brand-block">
          <div class="brand-name">{{ companyName }}</div>
          <div class="brand-sub">PANEL ERP</div>
        </div>
      </div>

      <nav class="side-nav">
        <template v-for="group in nav" :key="group.label">
          <div class="nav-group-label">{{ group.label }}</div>
          <button
            v-for="item in group.items"
            v-show="(!item.admin || isAdmin) && (!item.perm || can(item.perm))"
            :key="item.key"
            :class="['nav-item', { active: active === item.key }]"
            @click="go(item.key)"
          >
            <span class="nav-icon" v-html="item.icon"></span>
            <span class="nav-label">{{ item.label }}</span>
            <span v-if="badges[item.key]" :class="['nav-badge', { danger: item.badgeDanger }]">{{ badges[item.key] }}</span>
          </button>
        </template>
      </nav>

      <div class="side-user">
        <div class="su-avatar">{{ initials }}</div>
        <div class="su-meta">
          <div class="su-name">{{ user?.name ?? 'User' }}</div>
          <div class="su-role">{{ roleLabel }}</div>
        </div>
        <button class="su-logout" title="Logout" aria-label="Log out" @click="$emit('logout')">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M15 12H3m0 0l4-4m-4 4l4 4M21 3v18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
      </div>
    </aside>

    <!-- Main -->
    <div class="main">
      <header class="topbar">
        <div class="tb-left">
          <button class="hamburger" aria-label="Toggle navigation menu" @click="mobileOpen = !mobileOpen">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          </button>
          <div class="tb-titles">
            <div class="breadcrumb"><span>{{ activeGroup }}</span><span class="bc-sep">/</span><span class="bc-cur">{{ activeLabel }}</span></div>
            <h1 class="page-title">{{ activeLabel }}</h1>
          </div>
        </div>
        <div class="tb-right">
          <div class="search">
            <svg class="s-icon" width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="m20 20-3-3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            <input v-model="searchQ" aria-label="Jump to a section" placeholder="Jump to a section…" @keyup.enter="quickJump" />
            <span class="kbd">↵</span>
          </div>
          <button class="icon-btn" aria-label="View receivables alerts" title="Receivables alerts" @click="go('receivables')">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span v-if="alertCount" class="bell-dot"></span>
          </button>
          <button class="btn-primary" @click="go('quotations')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
            New Quote
          </button>
        </div>
      </header>

      <main class="content">
        <home-dashboard        v-if="active === 'dashboard'"   @navigate="go" />
        <lead-manager          v-else-if="active === 'leads'" @convert="onLeadConvert" />
        <customer-manager      v-else-if="active === 'customers'" @open-quotation="openQuotation" @view-orders="go('orders')" />
        <quotation-manager     v-else-if="active === 'quotations'" :open-id="quotationOpenId" :prefill="quotationPrefill" @order-created="go('orders')" />
        <boq-manager           v-else-if="active === 'boq'" @open-quotation="openQuotation" />
        <order-manager         v-else-if="active === 'orders'"      @view-quotation="go('quotations')" @view-batch="go('batches')" />
        <batch-manager         v-else-if="active === 'batches'"     @view-order="go('orders')" />
        <production-planner     v-else-if="active === 'planner'"     @view-order="go('orders')" @view-runs="go('runs')" />
        <production-runs        v-else-if="active === 'runs'"        @view-order="go('orders')" @go-planner="go('planner')" />
        <qc-dashboard          v-else-if="active === 'qc'"          @view-batch="go('batches')" />
        <stock-manager         v-else-if="active === 'stock'" />
        <procurement-manager   v-else-if="active === 'procurement'" />
        <dispatch-manager      v-else-if="active === 'dispatches'"  @view-batch="go('batches')" />
        <invoice-manager       v-else-if="active === 'invoices'" />
        <accounts-receivable   v-else-if="active === 'receivables'" @view-invoice="go('invoices')" />
        <business-reports      v-else-if="active === 'reports'" />
        <company-settings      v-else-if="active === 'company'" />
        <notification-settings v-else-if="active === 'notifications'" />
        <document-templates    v-else-if="active === 'doctemplates'" />
        <audit-log             v-else-if="active === 'audit'" />
        <master-data-manager   v-else-if="active === 'master'" />
        <user-management       v-else-if="active === 'users'" />
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import authService from '../services/authService.js'
import dashboardService from '../services/dashboardService.js'
import companyService from '../services/companyService.js'
import productionService from '../services/productionService.js'
import leadService from '../services/leadService.js'

import HomeDashboard      from './HomeDashboard.vue'
import LeadManager        from './LeadManager.vue'
import CustomerManager    from './CustomerManager.vue'
import QuotationManager   from './QuotationManager.vue'
import BoqManager         from './BoqManager.vue'
import OrderManager       from './OrderManager.vue'
import BatchManager       from './BatchManager.vue'
import ProductionPlanner  from './ProductionPlanner.vue'
import ProductionRuns     from './ProductionRuns.vue'
import QcDashboard        from './QcDashboard.vue'
import StockManager       from './StockManager.vue'
import ProcurementManager from './ProcurementManager.vue'
import DispatchManager    from './DispatchManager.vue'
import InvoiceManager     from './InvoiceManager.vue'
import AccountsReceivable from './AccountsReceivable.vue'
import BusinessReports    from './BusinessReports.vue'
import CompanySettings    from './CompanySettings.vue'
import NotificationSettings from './NotificationSettings.vue'
import DocumentTemplates  from './DocumentTemplates.vue'
import AuditLog           from './AuditLog.vue'
import MasterDataManager  from './MasterDataManager.vue'
import UserManagement     from './UserManagement.vue'

defineEmits(['logout'])

const active      = ref('dashboard')
const user        = ref(authService.currentUser())
const companyName = ref('PanelOS')
const companyLogo = ref(null)
const badges      = ref({})
const alertCount  = ref(0)
const quotationOpenId = ref(null)
const quotationPrefill = ref(null)

function onLeadConvert(payload) {
  quotationPrefill.value = payload   // { customer_id, lead_id }
  quotationOpenId.value = null
  active.value = 'quotations'
  mobileOpen.value = false
}
const mobileOpen  = ref(false)
const searchQ     = ref('')

function openQuotation(id) {
  quotationOpenId.value = id
  active.value = 'quotations'
}

const ic = {
  dashboard:  '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 10.5 12 3l9 7.5M5 9v11h14V9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  quote:      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 2h9l5 5v15H6z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M14 2v6h6M9 13h6M9 17h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
  lead:       '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 5h18M6 12h12M10 19h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
  order:      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="m3 7 9-4 9 4-9 4-9-4Zm0 0v10l9 4 9-4V7" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>',
  factory:    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 21V9l6 4V9l6 4V5l6 16H3Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>',
  plan:       '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M9 3h6v3H9zM6 6h12v15H6z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  qc:         '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6l-8-3Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  stock:      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5v-9Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M3 7.5 12 12m0 0 9-4.5M12 12v9" stroke="currentColor" stroke-width="2"/></svg>',
  truck:      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 6h11v9H3zM14 9h4l3 3v3h-7" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="7" cy="17.5" r="1.8" stroke="currentColor" stroke-width="2"/><circle cx="17" cy="17.5" r="1.8" stroke="currentColor" stroke-width="2"/></svg>',
  invoice:    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2V3Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M9 8h6M9 12h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
  money:      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M9 8h6M9 11h6m-4 0c2 0 3 1 3 3s-2 3-4 3l3 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  chart:      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 20V4M4 20h16M8 16v-4m4 4V8m4 8v-6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
  building:   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 21V5l8-2 8 2v16M4 21h16M9 8h0m3 0h0m3 0h0M9 12h0m3 0h0m3 0h0M9 16h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
  grid:       '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="2"/><rect x="14" y="3" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="2"/><rect x="3" y="14" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="2"/><rect x="14" y="14" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="2"/></svg>',
  users:      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="9" cy="8" r="3.2" stroke="currentColor" stroke-width="2"/><path d="M3 20c0-3 2.7-5 6-5s6 2 6 5M16 4a3.2 3.2 0 0 1 0 8m5 8c0-2.5-1.5-4.3-4-4.8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
  bell:       '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0 1 18 14.158V11a6 6 0 0 0-5-5.917V4a1 1 0 0 0-2 0v1.083A6 6 0 0 0 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 0 1-6 0v-1m6 0H9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
}

const nav = [
  { label: 'Main', items: [
    { key: 'dashboard',   label: 'Dashboard',       icon: ic.dashboard },
    { key: 'leads',       label: 'Leads',           icon: ic.lead, badgeDanger: true },
    { key: 'customers',   label: 'Customers',       icon: ic.users },
    { key: 'quotations',  label: 'Quotations',      icon: ic.quote },
    { key: 'boq',         label: 'BOQ Register',    icon: ic.grid },
    { key: 'orders',      label: 'Orders',          icon: ic.order },
    { key: 'batches',     label: 'Production',       icon: ic.factory },
    { key: 'planner',     label: 'Production Plan',  icon: ic.plan, badgeDanger: true },
    { key: 'runs',        label: 'Production Runs',  icon: ic.factory },
    { key: 'qc',          label: 'Quality Control', icon: ic.qc },
  ]},
  { label: 'Inventory', items: [
    { key: 'stock',       label: 'Stock',           icon: ic.stock },
    { key: 'procurement', label: 'Procurement',     icon: ic.order, perm: 'procurement.view' },
  ]},
  { label: 'Sales & Finance', items: [
    { key: 'dispatches',  label: 'Dispatches',      icon: ic.truck },
    { key: 'invoices',    label: 'Invoices',        icon: ic.invoice },
    { key: 'receivables', label: 'Receivables',     icon: ic.money, badgeDanger: true },
    { key: 'reports',     label: 'Reports',         icon: ic.chart, perm: 'reports.view' },
  ]},
  { label: 'Settings', items: [
    { key: 'company',       label: 'Company',         icon: ic.building },
    { key: 'notifications', label: 'Notifications',  icon: ic.bell },
    { key: 'doctemplates',  label: 'Doc Templates',  icon: ic.quote },
    { key: 'master',      label: 'Master Data',     icon: ic.grid },
    { key: 'users',       label: 'Users & Roles',   icon: ic.users, admin: true },
    { key: 'audit',       label: 'Audit Log',       icon: ic.qc, admin: true },
  ]},
]

const allItems    = nav.flatMap(g => g.items)
const activeLabel = computed(() => allItems.find(i => i.key === active.value)?.label ?? '')
const activeGroup = computed(() => nav.find(g => g.items.some(i => i.key === active.value))?.label ?? 'Main')
const initials = computed(() => {
  const n = user.value?.name ?? 'U'
  return n.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase()
})
const roleLabel = computed(() => user.value?.is_super_admin ? 'Super Admin' : (user.value?.is_company_admin ? 'Company Admin' : 'User'))
const isAdmin = computed(() => !!(user.value?.is_admin || user.value?.is_company_admin || user.value?.is_super_admin))
function can(key) {
  const u = user.value
  if (!u) return false
  if (u.is_admin || u.is_company_admin || u.is_super_admin) return true
  const p = u.permissions || []
  return p.includes('*') || p.includes(key)
}

function go(key) { quotationOpenId.value = null; quotationPrefill.value = null; active.value = key; mobileOpen.value = false }

// Quick-jump: match the typed text against nav labels and navigate.
function quickJump() {
  const q = searchQ.value.trim().toLowerCase()
  if (!q) return
  const hit = allItems.find(i => i.label.toLowerCase().includes(q)) || allItems.find(i => i.key.includes(q))
  if (hit) { go(hit.key); searchQ.value = '' }
}

onMounted(async () => {
  // Refresh the user so permissions/is_admin are current (drives nav gating).
  try { const u = await authService.me(); if (u) user.value = u } catch { /* ignore */ }
  try {
    const res = await dashboardService.get()
    const d = res?.data ?? res
    const k = d?.kpis ?? {}
    badges.value = {
      quotations:  k.open_quotations || 0,
      orders:      k.active_orders || 0,
      batches:     k.batches_in_production || 0,
      dispatches:  k.pending_dispatch || 0,
      receivables: k.overdue_invoice_count || 0,
    }
    alertCount.value = (d?.alerts ?? []).length
  } catch { /* ignore */ }
  try {
    const p = (await productionService.planning())?.data ?? {}
    if (p?.summary?.alert_count) badges.value = { ...badges.value, planner: p.summary.alert_count }
  } catch { /* ignore */ }
  try {
    const due = (await leadService.list({ follow_up: 'due' }))?.data ?? []
    if (due.length) badges.value = { ...badges.value, leads: due.length }
  } catch { /* ignore */ }
  try {
    const c = (await companyService.get())?.data ?? {}
    companyName.value = c?.name ?? 'PanelOS'
    companyLogo.value = c?.logo_url ?? null
  } catch { /* ignore */ }
})
</script>

<style scoped>
.shell { display: flex; min-height: 100vh; }

/* ---- Sidebar ---- */
.sidebar { width: 252px; flex-shrink: 0; display: flex; flex-direction: column; background: var(--brand-ink); color: #aeb4c9; }

.side-brand { display: flex; align-items: center; gap: 11px; padding: 18px 18px 16px; cursor: pointer; }
.brand-mark { width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0; background: linear-gradient(135deg, var(--accent), var(--accent-600)); display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(61,90,241,0.4); overflow: hidden; }
.brand-logo-img { width: 100%; height: 100%; object-fit: cover; }
.brand-name { font-size: 15px; font-weight: 800; color: #fff; letter-spacing: -0.01em; line-height: 1.15; }
.brand-sub { font-size: 9.5px; color: #6b7299; letter-spacing: 2px; font-weight: 600; margin-top: 1px; }

.side-nav { flex: 1; overflow-y: auto; padding: 6px 12px 12px; }
.nav-group-label { font-size: 10px; text-transform: uppercase; letter-spacing: 1.3px; color: #5a6088; margin: 16px 10px 7px; font-weight: 700; }
.nav-item { display: flex; align-items: center; gap: 12px; width: 100%; padding: 9px 12px; border: none; background: none; color: #aeb4c9; border-radius: var(--r); cursor: pointer; font-size: 13.5px; font-weight: 500; text-align: left; margin-bottom: 2px; transition: background var(--t-fast), color var(--t-fast); }
.nav-item:hover { background: rgba(255,255,255,0.05); color: #fff; }
.nav-item.active { background: #fff; color: var(--brand-900); font-weight: 600; box-shadow: var(--shadow-sm); }
.nav-icon { width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.nav-label { flex: 1; }
.nav-badge { font-family: var(--mono); font-size: 11px; font-weight: 600; background: rgba(255,255,255,0.12); color: #c7cce0; border-radius: var(--r-pill); padding: 1px 8px; min-width: 20px; text-align: center; }
.nav-item.active .nav-badge { background: var(--brand-50); color: var(--brand-700); }
.nav-badge.danger { background: var(--danger); color: #fff; }

.side-user { display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-top: 1px solid rgba(255,255,255,0.07); }
.su-avatar { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, var(--accent), var(--accent-600)); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0; }
.su-meta { flex: 1; line-height: 1.25; min-width: 0; }
.su-name { font-size: 13px; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.su-role { font-size: 11px; color: #6b7299; }
.su-logout { background: none; border: none; color: #6b7299; cursor: pointer; padding: 6px; border-radius: var(--r-sm); display: flex; transition: all var(--t-fast); }
.su-logout:hover { color: #fff; background: rgba(255,255,255,0.07); }

/* ---- Topbar ---- */
.main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.topbar { background: var(--surface); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 12px 26px; gap: 20px; flex-shrink: 0; position: sticky; top: 0; z-index: 20; }
.tb-left { min-width: 0; }
.breadcrumb { font-size: 12px; color: var(--text-3); display: flex; gap: 7px; align-items: center; }
.bc-sep { color: #cbd3e3; }
.bc-cur { color: var(--text-2); font-weight: 500; }
.page-title { font-size: 21px; font-weight: 800; color: var(--text); letter-spacing: -0.02em; margin-top: 1px; }

.tb-right { display: flex; align-items: center; gap: 12px; }
.search { display: flex; align-items: center; gap: 9px; background: var(--surface-3); border: 1px solid transparent; border-radius: var(--r); padding: 9px 13px; width: 340px; transition: all var(--t-fast); }
.search:focus-within { background: #fff; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-50); }
.s-icon { color: var(--text-3); flex-shrink: 0; }
.search input { border: none; background: none; outline: none; font-size: 13.5px; flex: 1; color: var(--text); min-width: 0; }
.kbd { font-size: 11px; color: var(--text-3); background: var(--surface); border: 1px solid var(--border-2); border-radius: 5px; padding: 1px 6px; font-weight: 600; }

.icon-btn { position: relative; width: 40px; height: 40px; border: 1px solid var(--border); background: var(--surface); border-radius: var(--r); color: var(--text-2); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all var(--t-fast); }
.icon-btn:hover { background: var(--surface-3); color: var(--text); }
.bell-dot { position: absolute; top: 9px; right: 10px; width: 7px; height: 7px; border-radius: 50%; background: var(--danger); border: 1.5px solid var(--surface); }

.btn-primary { display: flex; align-items: center; gap: 7px; padding: 10px 16px; border: none; border-radius: var(--r); background: linear-gradient(135deg, var(--accent), var(--accent-600)); color: #fff; font-size: 13.5px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 12px rgba(61,90,241,0.28); transition: transform var(--t-fast), box-shadow var(--t-fast); white-space: nowrap; }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(61,90,241,0.36); }

.content { flex: 1; overflow-y: auto; }

/* ---- Hamburger (mobile) ---- */
.hamburger { display: none; align-items: center; justify-content: center; width: 38px; height: 38px; margin-right: 6px; border: 1px solid var(--border); background: var(--surface); border-radius: var(--r-sm); color: var(--text-2); cursor: pointer; flex-shrink: 0; }
.hamburger:hover { background: var(--surface-3); }
.sidebar-scrim { display: none; }

/* ---- Responsive ---- */
@media (max-width: 1024px) {
  .search { width: 200px; }
}
@media (max-width: 860px) {
  .sidebar {
    position: fixed; top: 0; left: 0; bottom: 0; z-index: 60;
    transform: translateX(-100%); transition: transform var(--t);
    box-shadow: var(--shadow-lg);
  }
  .sidebar.open { transform: translateX(0); }
  .sidebar-scrim { display: block; position: fixed; inset: 0; background: rgba(16,24,40,0.45); z-index: 55; }
  .hamburger { display: flex; }
  .search { display: none; }
  .topbar { padding: 12px 16px; }
}
</style>
