<template>
  <div class="app-layout">
    <!-- Sidebar -->
    <aside class="sidebar">
      <div class="sidebar-logo">
        <span>🏭</span> PanelOS
      </div>

      <nav>
        <div class="sidebar-section">
          <div class="sidebar-section-title">Main</div>
          <router-link to="/dashboard" class="sidebar-link" active-class="active">
            <span class="sidebar-icon">📊</span> Dashboard
          </router-link>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-title">Sales</div>
          <router-link to="/customers" class="sidebar-link" active-class="active">
            <span class="sidebar-icon">👥</span> Customers
          </router-link>
          <router-link to="/quotations" class="sidebar-link" active-class="active">
            <span class="sidebar-icon">📋</span> Quotations / BOQ
          </router-link>
          <router-link to="/orders" class="sidebar-link" active-class="active">
            <span class="sidebar-icon">🛒</span> Orders
          </router-link>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-title">Operations</div>
          <router-link to="/production" class="sidebar-link" active-class="active">
            <span class="sidebar-icon">⚙️</span> Production
          </router-link>
          <router-link to="/dispatches" class="sidebar-link" active-class="active">
            <span class="sidebar-icon">🚚</span> Dispatches
          </router-link>
          <router-link to="/stock" class="sidebar-link" active-class="active">
            <span class="sidebar-icon">📦</span> Stock
          </router-link>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-title">Finance</div>
          <router-link to="/invoices" class="sidebar-link" active-class="active">
            <span class="sidebar-icon">🧾</span> Invoices
          </router-link>
          <router-link to="/payments" class="sidebar-link" active-class="active">
            <span class="sidebar-icon">💳</span> Payments
          </router-link>
          <router-link to="/gst" class="sidebar-link" active-class="active">
            <span class="sidebar-icon">🏛️</span> GST
          </router-link>
          <router-link to="/reports" class="sidebar-link" active-class="active">
            <span class="sidebar-icon">📈</span> Reports
          </router-link>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-title">Intelligence</div>
          <router-link to="/forecasting" class="sidebar-link" active-class="active">
            <span class="sidebar-icon">🤖</span> ML Forecasting
          </router-link>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-title">Configuration</div>
          <router-link to="/settings" class="sidebar-link" active-class="active">
            <span class="sidebar-icon">⚙️</span> Settings
          </router-link>
        </div>
      </nav>
    </aside>

    <!-- Main area -->
    <div class="main-area">
      <header class="topbar">
        <div class="topbar-left">{{ pageTitle }}</div>
        <div class="topbar-right">
          <div class="user-badge">
            👤 {{ auth.userName }}
            <span style="font-size:11px;opacity:.7">({{ auth.userRole }})</span>
          </div>
          <button class="btn-logout" @click="logout">Logout</button>
        </div>
      </header>

      <main class="page-content">
        <router-view />
      </main>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const titleMap = {
  '/dashboard': 'Dashboard',
  '/customers': 'Customers',
  '/quotations': 'Quotations / BOQ',
  '/orders': 'Orders',
  '/production': 'Production Batches',
  '/dispatches': 'Dispatches',
  '/stock': 'Stock Management',
  '/invoices': 'Invoices',
  '/payments': 'Payments',
  '/gst': 'GST Configuration',
  '/reports': 'Financial Reports',
  '/forecasting': 'ML Forecasting',
}

const pageTitle = computed(() => {
  const base = '/' + route.path.split('/')[1]
  return titleMap[base] || 'PanelOS'
})

const logout = async () => {
  await auth.logout()
  router.push('/login')
}
</script>
