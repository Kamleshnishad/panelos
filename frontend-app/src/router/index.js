import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', component: () => import('@/views/auth/LoginView.vue'), meta: { public: true } },
    {
      path: '/',
      component: () => import('@/views/dashboard/DashboardLayout.vue'),
      children: [
        { path: '', redirect: '/dashboard' },
        { path: 'dashboard', component: () => import('@/views/dashboard/DashboardView.vue') },
        { path: 'customers', component: () => import('@/views/customers/CustomersView.vue') },
        { path: 'quotations', component: () => import('@/views/quotations/QuotationsView.vue') },
        { path: 'quotations/create', component: () => import('@/views/quotations/QuotationCreate.vue') },
        { path: 'quotations/:id/edit', component: () => import('@/views/quotations/QuotationCreate.vue') },
        { path: 'quotations/:id', component: () => import('@/views/quotations/QuotationDetail.vue') },
        { path: 'orders', component: () => import('@/views/orders/OrdersView.vue') },
        { path: 'orders/:id', component: () => import('@/views/orders/OrderDetail.vue') },
        { path: 'production', component: () => import('@/views/production/ProductionView.vue') },
        { path: 'production/:id', component: () => import('@/views/production/BatchDetail.vue') },
        { path: 'dispatches', component: () => import('@/views/dispatches/DispatchesView.vue') },
        { path: 'stock', component: () => import('@/views/stock/StockView.vue') },
        { path: 'invoices', component: () => import('@/views/invoices/InvoicesView.vue') },
        { path: 'invoices/:id', component: () => import('@/views/invoices/InvoiceDetail.vue') },
        { path: 'payments', component: () => import('@/views/payments/PaymentsView.vue') },
        { path: 'reports', component: () => import('@/views/reports/ReportsView.vue') },
        { path: 'gst', component: () => import('@/views/gst/GstView.vue') },
        { path: 'forecasting', component: () => import('@/views/forecasting/ForecastingView.vue') },
        { path: 'settings', component: () => import('@/views/settings/SettingsView.vue') },
      ]
    },
    { path: '/:catchAll(.*)', redirect: '/dashboard' }
  ]
})

router.beforeEach((to) => {
  const token = localStorage.getItem('token')
  if (!to.meta.public && !token) return '/login'
  if (to.path === '/login' && token) return '/dashboard'
})

export default router
