import { createRouter, createWebHistory } from 'vue-router'
import OrderList from './components/OrderList.vue'
import OrderDetail from './components/OrderDetail.vue'
import BatchList from './components/BatchList.vue'
import BatchDetail from './components/BatchDetail.vue'

const routes = [
  {
    path: '/',
    redirect: '/orders'
  },
  {
    path: '/orders',
    component: OrderList,
    meta: { title: 'Orders' }
  },
  {
    path: '/orders/:orderId',
    component: OrderDetail,
    props: true,
    meta: { title: 'Order Details' }
  },
  {
    path: '/orders/:orderId/create-batch',
    component: () => import('./components/CreateBatchForm.vue'),
    props: true,
    meta: { title: 'Create Production Batch' }
  },
  {
    path: '/batches',
    component: BatchList,
    meta: { title: 'Production Batches' }
  },
  {
    path: '/batches/:batchId',
    component: BatchDetail,
    props: true,
    meta: { title: 'Batch Details' }
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

router.beforeEach((to, from, next) => {
  document.title = to.meta.title ? `${to.meta.title} | Production System` : 'Production System'
  next()
})

export default router
