import axios from 'axios'

function authHeaders() {
  const token = localStorage.getItem('token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}
function api(method, url, data = null) {
  return axios({ method, url, data, headers: authHeaders() }).then(r => r.data)
}

let razorpayLoaded = false
function loadRazorpay() {
  return new Promise((resolve, reject) => {
    if (razorpayLoaded || window.Razorpay) { razorpayLoaded = true; return resolve() }
    const s = document.createElement('script')
    s.src = 'https://checkout.razorpay.com/v1/checkout.js'
    s.onload = () => { razorpayLoaded = true; resolve() }
    s.onerror = () => reject(new Error('Could not load Razorpay. Check your internet connection.'))
    document.body.appendChild(s)
  })
}

export default {
  status()                  { return api('get',  '/api/billing/status') },
  checkout(plan, months, coupon) { return api('post', '/api/billing/checkout', { plan, months, coupon }) },
  verify(payload)           { return api('post', '/api/billing/verify', payload) },

  /**
   * Full online-payment flow: create order → open Razorpay → verify.
   * Resolves with the verify response on success, rejects on failure/cancel.
   */
  async pay(plan, months, company, coupon = null) {
    await loadRazorpay()
    const res = await this.checkout(plan, months, coupon)
    const o = res?.data ?? res
    return new Promise((resolve, reject) => {
      const rzp = new window.Razorpay({
        key: o.key,
        amount: o.amount,
        currency: o.currency,
        order_id: o.order_id,
        name: 'PanelOS',
        description: `${plan} plan — ${months} month(s)`,
        prefill: { name: company?.name ?? '', email: company?.email ?? '' },
        theme: { color: '#1a237e' },
        handler: async (resp) => {
          try {
            const v = await this.verify({
              razorpay_order_id: resp.razorpay_order_id,
              razorpay_payment_id: resp.razorpay_payment_id,
              razorpay_signature: resp.razorpay_signature,
              plan, months, coupon: o.coupon || null, amount: o.payable,
            })
            resolve(v?.data ?? v)
          } catch (e) { reject(e) }
        },
        modal: { ondismiss: () => reject(new Error('Payment cancelled.')) },
      })
      rzp.open()
    })
  },
}
