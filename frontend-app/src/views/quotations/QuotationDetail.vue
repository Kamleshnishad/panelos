<template>
  <div>
    <div v-if="loading" class="loading"><div class="spinner"></div></div>
    <template v-else-if="q">
      <!-- Page header -->
      <div class="page-header">
        <div>
          <button class="btn btn-outline btn-sm" @click="$router.back()">← Back</button>
          <div class="page-title" style="margin-top:8px;display:flex;align-items:center;gap:10px">
            {{ q.quotation_no }}
            <span :class="'badge badge-'+q.status">{{ q.status }}</span>
            <span v-if="q.revision_number > 1" style="font-size:12px;background:#f3e5f5;color:#6a1b9a;padding:2px 8px;border-radius:10px;font-weight:600">Rev {{ q.revision_number }}</span>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button v-if="q.status==='draft'" class="btn btn-primary" @click="$router.push('/quotations/'+q.id+'/edit')">✏ Edit</button>
          <button v-if="q.status==='draft'" class="btn btn-success" :disabled="acting" @click="act('send')">Send to Customer</button>
          <button v-if="q.status==='sent'"  class="btn btn-success" :disabled="acting" @click="act('accept')">Accept</button>
          <button v-if="['draft','sent'].includes(q.status)" class="btn btn-outline" style="color:#c62828;border-color:#c62828" :disabled="acting" @click="act('reject')">Reject</button>
          <button v-if="['sent','accepted'].includes(q.status)" class="btn btn-outline" :disabled="acting" @click="act('revise')">Revise</button>
          <button v-if="q.status==='accepted'" class="btn btn-success" :disabled="acting" @click="act('create-order')">Create Order</button>
          <button class="btn btn-outline" :disabled="acting" @click="act('duplicate')" title="Copy as new draft">⊕ Duplicate</button>
          <button v-if="['draft','sent'].includes(q.status)" class="btn btn-outline" style="color:#888" :disabled="acting" @click="act('expire')">Expire</button>
          <button v-if="q.status==='draft'" class="btn btn-outline" style="color:#c62828" :disabled="acting" @click="confirmDel=true">Delete</button>
          <a :href="pdfUrl" target="_blank" class="btn btn-outline" style="color:#c62828;border-color:#c62828">📄 PDF</a>
        </div>
      </div>

      <div v-if="errMsg" class="alert alert-error" style="margin-bottom:12px">{{ errMsg }}</div>
      <div v-if="okMsg"  class="alert alert-success" style="margin-bottom:12px">{{ okMsg }}</div>

      <!-- Info grid -->
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:16px">
        <div class="card" style="padding:16px">
          <div class="form-label">Customer</div>
          <div style="font-weight:700;font-size:15px">{{ q.customer?.name }}</div>
          <div style="font-size:12px;color:#888;margin-top:2px">{{ q.customer?.city }}, {{ q.customer?.state }}</div>
          <div v-if="q.customer?.gstin" style="font-size:11px;color:#888">GSTIN: {{ q.customer.gstin }}</div>
          <div v-if="q.customer?.phone" style="font-size:11px;color:#888">📞 {{ q.customer.phone }}</div>
        </div>
        <div class="card" style="padding:16px">
          <div class="form-label">Project</div>
          <div style="font-weight:600">{{ q.project_name || '—' }}</div>
          <div style="font-size:12px;color:#888;margin-top:2px">{{ q.project_location || '' }}</div>
          <div style="font-size:12px;margin-top:4px">Grade: <strong>{{ q.quality_grade }}</strong></div>
        </div>
        <div class="card" style="padding:16px">
          <div class="form-label">Dates</div>
          <div style="font-size:13px">Issued: <strong>{{ fmtDate(q.quoted_on) }}</strong></div>
          <div style="font-size:13px" :style="isExpired(q.valid_until)?'color:#c62828;font-weight:600':''">Valid Until: <strong>{{ fmtDate(q.valid_until) }}</strong></div>
          <div v-if="q.sent_at" style="font-size:11px;color:#888;margin-top:4px">Sent: {{ fmtDate(q.sent_at) }}</div>
          <div v-if="q.accepted_at" style="font-size:11px;color:#2e7d32">Accepted: {{ fmtDate(q.accepted_at) }}</div>
          <div v-if="q.rejected_at" style="font-size:11px;color:#c62828">Rejected: {{ fmtDate(q.rejected_at) }}</div>
        </div>
        <div class="card" style="padding:16px">
          <div class="form-label">GST Type</div>
          <div style="font-weight:600;font-size:13px">{{ q.is_inter_state ? 'IGST (Inter-state)' : 'CGST + SGST' }}</div>
          <div style="font-size:11px;color:#888;margin-top:4px">Total SQM: <strong>{{ Number(q.total_sqm||0).toFixed(2) }}</strong></div>
          <div style="font-size:11px;color:#888">Validity: {{ q.validity_days }} days</div>
        </div>
      </div>

      <!-- Revision chain -->
      <div v-if="q.parent || q.revisions?.length" class="card" style="margin-bottom:16px;padding:14px 20px">
        <div class="card-title" style="margin-bottom:8px;font-size:13px">Revision History</div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:13px">
          <template v-if="q.parent">
            <a @click="$router.push('/quotations/'+q.parent.id)" style="cursor:pointer;color:var(--primary)">{{ q.parent.quotation_no }}</a>
            <span :class="'badge badge-'+q.parent.status" style="font-size:10px">{{ q.parent.status }}</span>
            <span style="color:#aaa">→</span>
          </template>
          <strong>{{ q.quotation_no }} (current)</strong>
          <template v-for="rev in q.revisions" :key="rev.id">
            <span style="color:#aaa">→</span>
            <a @click="$router.push('/quotations/'+rev.id)" style="cursor:pointer;color:var(--primary)">{{ rev.quotation_no }}</a>
            <span :class="'badge badge-'+rev.status" style="font-size:10px">{{ rev.status }}</span>
          </template>
        </div>
      </div>

      <!-- Panel items -->
      <div class="card" style="margin-bottom:16px">
        <div class="card-header"><div class="card-title">Panel Specifications & BOQ</div></div>

        <div v-for="(item, ii) in q.items" :key="item.id" class="panel-item-block">
          <div class="panel-item-header">
            <div style="display:flex;align-items:center;gap:10px">
              <span style="background:var(--primary);color:white;border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0">{{ ii+1 }}</span>
              <div>
                <div style="font-weight:700;color:var(--primary)">{{ item.panel_type?.name }}</div>
                <div style="font-size:11px;color:#666;line-height:1.7;margin-top:2px">
                  <span>{{ item.thickness }}mm &nbsp;|&nbsp; {{ item.density_type }} {{ item.density_kgm3 }} kg/m³</span><br>
                  <span>TOP: {{ item.top_skin_thickness }}mm {{ item.top_skin_material }} {{ item.top_color }} ({{ item.top_surface }})</span><br>
                  <span>BTM: {{ item.bottom_skin_thickness }}mm {{ item.bottom_skin_material }} {{ item.bottom_color }} (PLAIN)</span>
                  <span v-if="item.guard_film"> &nbsp;|&nbsp; Guard Film</span>
                  <span v-if="item.cello_tap"> &nbsp;|&nbsp; Cello Tap</span>
                  &nbsp;|&nbsp; HSN: {{ item.hsn_code }}
                </div>
              </div>
            </div>
            <div style="text-align:right;flex-shrink:0">
              <div style="font-size:12px;color:#888">{{ Number(item.total_sqm||0).toFixed(2) }} SQM</div>
              <div style="font-size:17px;font-weight:700;color:var(--primary)">₹{{ fmt(item.amount) }}</div>
            </div>
          </div>

          <!-- Size sub-table -->
          <div class="table-wrap" v-if="item.sizes?.length">
            <table style="font-size:12px">
              <thead>
                <tr>
                  <th>Length (mm)</th>
                  <th>Width (mm)</th>
                  <th>NOS</th>
                  <th>SQM</th>
                  <th class="text-right">Rate (₹/SQM)</th>
                  <th class="text-right">Amount (₹)</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="sz in item.sizes" :key="sz.id">
                  <td style="font-weight:600">
                    {{ sz.length_mm }}
                    <span v-if="sz.length_mm < 2000" style="background:#fff3e0;color:#e65100;border:1px solid #ffcc80;border-radius:3px;padding:1px 4px;font-size:9px;font-weight:700;margin-left:4px">⚠ DL</span>
                  </td>
                  <td>{{ sz.width_mm }}</td>
                  <td style="font-weight:600">{{ sz.nos }}</td>
                  <td style="font-weight:600;color:var(--primary)">{{ Number(sz.sqm||0).toFixed(3) }}</td>
                  <td class="text-right">{{ fmtD(sz.rate_per_sqm) }}</td>
                  <td class="text-right" style="font-weight:600">{{ fmtD(sz.amount) }}</td>
                </tr>
                <tr style="background:#f0f4ff;font-weight:700">
                  <td colspan="2" style="text-align:right;padding:6px 12px">Total</td>
                  <td>{{ item.sizes.reduce((s,z)=>s+z.nos,0) }}</td>
                  <td style="color:var(--primary)">{{ Number(item.total_sqm||0).toFixed(3) }}</td>
                  <td></td>
                  <td class="text-right">₹{{ fmt(item.amount) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Accessories -->
        <template v-if="q.accessories?.length">
          <div style="font-weight:600;font-size:13px;margin:14px 0 8px;color:#555">Accessories</div>
          <div class="table-wrap">
            <table style="font-size:12px">
              <thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th class="text-right">Rate (₹)</th><th class="text-right">Amount (₹)</th></tr></thead>
              <tbody>
                <tr v-for="acc in q.accessories" :key="acc.id">
                  <td>{{ acc.name }}<div v-if="acc.description" style="font-size:10px;color:#888">{{ acc.description }}</div></td>
                  <td>{{ acc.pivot?.quantity }}</td>
                  <td>{{ acc.unit || 'NOS' }}</td>
                  <td class="text-right">{{ fmtD(acc.pivot?.unit_price) }}</td>
                  <td class="text-right" style="font-weight:600">{{ fmtD(acc.pivot?.amount) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </div>

      <!-- Financial summary + notes -->
      <div style="display:grid;grid-template-columns:1fr 480px;gap:16px;margin-bottom:16px">
        <div class="card" style="padding:16px">
          <div class="card-title" style="margin-bottom:12px">Notes</div>
          <div style="font-size:13px;color:#555;line-height:1.8;white-space:pre-wrap">{{ q.notes || 'No special notes.' }}</div>
        </div>

        <div class="card" style="padding:16px">
          <div class="card-title" style="margin-bottom:12px">Financial Summary</div>
          <div class="sum-line"><span>Panel Subtotal</span><span>₹{{ fmtD(q.panel_subtotal) }}</span></div>
          <div v-if="q.accessory_subtotal > 0" class="sum-line"><span>Accessories</span><span>₹{{ fmtD(q.accessory_subtotal) }}</span></div>
          <div v-if="q.installation_amount > 0" class="sum-line"><span>Installation</span><span>₹{{ fmtD(q.installation_amount) }}</span></div>
          <div class="sum-line subtotal"><span>Subtotal</span><span>₹{{ fmtD(q.subtotal) }}</span></div>
          <div v-if="q.discount_pct > 0" class="sum-line" style="color:#c62828"><span>Discount ({{ q.discount_pct }}%)</span><span>- ₹{{ fmtD(q.discount_amount) }}</span></div>
          <div class="sum-line subtotal"><span>Taxable Amount</span><span>₹{{ fmtD(q.taxable_amount) }}</span></div>
          <template v-if="q.is_inter_state">
            <div class="sum-line"><span>IGST @ 18%</span><span>₹{{ fmtD(q.igst_amount) }}</span></div>
          </template>
          <template v-else>
            <div class="sum-line"><span>CGST @ 9%</span><span>₹{{ fmtD(q.cgst_amount) }}</span></div>
            <div class="sum-line"><span>SGST @ 9%</span><span>₹{{ fmtD(q.sgst_amount) }}</span></div>
          </template>
          <div class="sum-line">
            <span>Transportation</span>
            <span>{{ q.transport_fixed && q.transport_amount > 0 ? '₹'+fmtD(q.transport_amount) : 'Extra as Actual' }}</span>
          </div>
          <div v-if="q.round_off" class="sum-line"><span>Round Off</span><span>₹{{ fmtD(q.round_off) }}</span></div>
          <div class="sum-line grand"><span>GRAND TOTAL</span><span>₹{{ fmtD(q.total_amount) }}</span></div>
          <div class="sum-line"><span>Advance ({{ q.advance_pct }}%)</span><span>₹{{ fmtD(q.advance_amount) }}</span></div>
          <div class="sum-line" style="color:var(--primary);font-weight:700"><span>Balance Due</span><span>₹{{ fmtD(q.balance_amount) }}</span></div>
          <div class="sum-line" style="border-top:1px solid #eee;margin-top:6px;padding-top:6px;font-size:12px;color:#888">
            <span>Total SQM</span><span>{{ Number(q.total_sqm||0).toFixed(3) }} SQM</span>
          </div>
        </div>
      </div>
    </template>

    <div v-if="!loading && !q" class="card" style="padding:40px;text-align:center;color:#888">Quotation not found.</div>

    <!-- Delete confirm -->
    <div v-if="confirmDel" class="modal-overlay" @click.self="confirmDel=false">
      <div class="modal" style="max-width:400px">
        <div class="modal-header"><div class="modal-title" style="color:#c62828">Delete Quotation?</div><button class="modal-close" @click="confirmDel=false">✕</button></div>
        <div class="modal-body"><p>This will permanently delete <strong>{{ q.quotation_no }}</strong>. This cannot be undone.</p></div>
        <div class="modal-footer">
          <button class="btn btn-outline" @click="confirmDel=false">Cancel</button>
          <button class="btn btn-danger" :disabled="acting" @click="doDelete">{{ acting ? 'Deleting…' : 'Delete' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'

const route  = useRoute()
const router = useRouter()
const q = ref(null)
const loading = ref(true)
const acting = ref(false)
const errMsg = ref('')
const okMsg = ref('')
const confirmDel = ref(false)

const pdfUrl = computed(() => {
  const t = localStorage.getItem('token')
  return `/api/quotations/${route.params.id}/pdf${t ? '?token='+t : ''}`
})

const fmt  = (n) => n ? Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '0'
const fmtD = (n) => n ? Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0.00'
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const isExpired = (d) => d && new Date(d) < new Date()

async function load() {
  loading.value = true
  errMsg.value = ''
  try {
    const { data } = await api.get(`/quotations/${route.params.id}`)
    q.value = data.data ?? data
  } catch (e) {
    errMsg.value = e.response?.data?.message || 'Failed to load quotation.'
  } finally {
    loading.value = false
  }
}

async function act(type) {
  acting.value = true; errMsg.value = ''; okMsg.value = ''
  try {
    if (type === 'send')   { await api.post(`/quotations/${q.value.id}/send`);   okMsg.value = 'Quotation sent.' }
    else if (type === 'accept') { await api.post(`/quotations/${q.value.id}/accept`); okMsg.value = 'Quotation accepted.' }
    else if (type === 'reject') { await api.post(`/quotations/${q.value.id}/reject`); okMsg.value = 'Quotation rejected.' }
    else if (type === 'revise') {
      const { data } = await api.post(`/quotations/${q.value.id}/revise`)
      const newId = data.data?.id ?? data.id
      router.push(`/quotations/${newId}/edit`)
      return
    } else if (type === 'create-order') {
      const { data } = await api.post(`/quotations/${q.value.id}/create-order`)
      const orderId = data.data?.id ?? data.id
      router.push(`/orders/${orderId}`)
      return
    } else if (type === 'duplicate') {
      const { data } = await api.post(`/quotations/${q.value.id}/duplicate`)
      const newId = data.data?.id ?? data.id
      router.push(`/quotations/${newId}/edit`)
      return
    } else if (type === 'expire') {
      await api.post(`/quotations/${q.value.id}/expire`)
      okMsg.value = 'Quotation marked as expired.'
    }
    await load()
  } catch (e) {
    errMsg.value = e.response?.data?.message || `Failed to ${type}.`
  } finally {
    acting.value = false
  }
}

async function doDelete() {
  acting.value = true
  try {
    await api.delete(`/quotations/${q.value.id}`)
    router.push('/quotations')
  } catch (e) {
    errMsg.value = e.response?.data?.message || 'Failed to delete.'
    confirmDel.value = false
  } finally {
    acting.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.panel-item-block { border:1px solid #e0e0e0;border-radius:6px;padding:14px;margin-bottom:14px;background:#fafbff; }
.panel-item-header { display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px; }
.sum-line { display:flex;justify-content:space-between;padding:6px 4px;border-bottom:1px solid #f0f0f0;font-size:13px; }
.sum-line.subtotal { font-weight:600;border-top:2px solid #ccc;border-bottom:2px solid #ccc;padding:9px 4px; }
.sum-line.grand { font-size:16px;font-weight:800;color:var(--primary);border-top:3px solid var(--primary);border-bottom:3px solid var(--primary);padding:12px 4px; }
.text-right { text-align:right; }
.btn-danger { background:#c62828;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;font-weight:600; }
</style>
