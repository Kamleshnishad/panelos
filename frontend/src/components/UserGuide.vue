<template>
  <div class="ug-wrap">
    <!-- Left index -->
    <aside class="ug-index">
      <div class="ug-index-title">User Guide</div>
      <a v-for="s in sections" :key="s.key" :href="'#g-' + s.key"
         :class="['ug-link', { on: activeKey === s.key }]" @click="activeKey = s.key">
        {{ s.title }}
      </a>
    </aside>

    <!-- Content -->
    <div class="ug-content">
      <div class="ug-hero">
        <h2>How to use PanelOS</h2>
        <p>A step-by-step guide to every module — from a new enquiry all the way to payment.
           Follow the natural flow: <b>Lead → Quotation → Order → Production → Dispatch → Invoice → Payment</b>.</p>
      </div>

      <section v-for="s in sections" :key="s.key" :id="'g-' + s.key" class="ug-section">
        <div class="ug-head">
          <span class="ug-num">{{ s.step }}</span>
          <div>
            <h3>{{ s.title }}</h3>
            <p class="ug-desc">{{ s.desc }}</p>
          </div>
        </div>

        <div class="ug-body">
          <ol class="ug-steps">
            <li v-for="(st, i) in s.steps" :key="i" v-html="st"></li>
          </ol>
          <figure class="ug-shot" v-if="s.img">
            <img :src="'/guide/' + s.img + '.png'" :alt="s.title" loading="lazy" @click="zoom = '/guide/' + s.img + '.png'" />
            <figcaption>Click to enlarge</figcaption>
          </figure>
        </div>

        <div v-if="s.tip" class="ug-tip">💡 <b>Tip:</b> {{ s.tip }}</div>
      </section>

      <div class="ug-foot">Need help? Contact support — your platform admin can also assist.</div>
    </div>

    <!-- Zoom overlay -->
    <div v-if="zoom" class="ug-zoom" @click="zoom = null">
      <img :src="zoom" alt="screenshot" />
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const zoom = ref(null)
const activeKey = ref('dashboard')

const sections = [
  { key: 'dashboard', step: 1, title: 'Dashboard', img: 'dashboard',
    desc: 'Your daily command centre — KPIs, alerts and the order-to-cash pipeline at a glance.',
    steps: [
      'Open <b>Dashboard</b> from the top of the sidebar.',
      'The KPI cards show open quotations, active orders, items in production, pending dispatch and outstanding money.',
      'The <b>Alerts</b> panel flags low stock and overdue invoices — click an alert to jump to it.',
      'The <b>Order-to-Cash Pipeline</b> shows how many records sit at each stage.',
    ],
    tip: 'Check the Dashboard first thing every morning — the red badges in the sidebar tell you what needs action today.' },

  { key: 'leads', step: 2, title: 'Leads (Enquiries)', img: 'leads',
    desc: 'Capture every enquiry, track follow-ups, and convert hot leads into quotations.',
    steps: [
      'Go to <b>Leads</b> and click <b>+ Add Lead</b> to record a new enquiry (name, phone, requirement, source).',
      'Set a <b>Next follow-up date</b> — leads due today show a red badge on the Leads menu.',
      'Open a lead to log activities (calls, site visits, notes).',
      'When the customer is ready, click <b>Convert</b> to turn the lead into a Quotation (details carry over).',
    ],
    tip: 'The red number on "Leads" = follow-ups due. Clear it daily so no enquiry is forgotten.' },

  { key: 'customers', step: 3, title: 'Customers', img: 'customers',
    desc: 'Your full customer directory with a deep 360° profile of every buyer.',
    steps: [
      'Go to <b>Customers</b> to see all customers. Use search to find one quickly.',
      'Click <b>+ Add Customer</b> to add a buyer (GSTIN, address, credit limit, payment terms).',
      'Click a customer name to open their <b>360° profile</b> — all quotations, orders, invoices, payments and repeat-order frequency.',
      'Set a <b>Credit Limit</b> here — the system blocks new orders that would exceed it.',
    ],
    tip: 'Fill GSTIN and state correctly — they drive CGST/SGST vs IGST on invoices.' },

  { key: 'quotations', step: 4, title: 'Quotations', img: 'quotations',
    desc: 'Build a priced quotation, send it, and convert the accepted one into an order.',
    steps: [
      'Go to <b>Quotations</b> → <b>New Quote</b> (top-right). Pick the customer.',
      'Add panel line items — choose panel type, thickness, skin, sizes; rates calculate automatically.',
      'Edit the <b>rate column inline</b> on the detail page; the financial summary updates live.',
      'Click <b>Send</b>, then <b>Accept</b> once approved, then <b>Create Order</b>.',
    ],
    tip: 'A quotation can be revised or duplicated — use Revise to keep a version history.' },

  { key: 'boq', step: 5, title: 'BOQ Register', img: 'boq',
    desc: 'A rate-less Bill of Quantities stage — capture sizes/quantities first, add rates later.',
    steps: [
      'Go to <b>BOQ Register</b> to see all BOQs.',
      'Create a BOQ when the customer has shared a cutting list but rates aren’t finalised.',
      'When ready, click <b>Convert to Quotation</b> to add rates and proceed.',
    ],
    tip: 'Use BOQ for big projects where sizes come first and pricing is negotiated later.' },

  { key: 'orders', step: 6, title: 'Orders', img: 'orders',
    desc: 'Confirmed orders ready to be produced, dispatched and invoiced.',
    steps: [
      'Orders are created from an accepted quotation (or BOQ).',
      'Open an order to see items, sizes and delivery date.',
      'From here the order flows into <b>Production</b> (planning & batches).',
    ],
    tip: 'If a customer is over their credit limit, order creation is blocked — an admin can override.' },

  { key: 'planner', step: 7, title: 'Production Plan', img: 'planner',
    desc: 'Smart planner that groups same-spec jobs together to cut line-changeover waste.',
    steps: [
      'Go to <b>Production Plan</b>. The system groups orders by panel specification.',
      'It alerts you which job to run first ("take this job first") so similar panels run back-to-back.',
      'Click <b>Create Run</b> to turn a group of same-spec orders into a production run.',
    ],
    tip: 'Following the planner’s grouping reduces foam/coil waste from frequent spec changes.' },

  { key: 'production', step: 8, title: 'Production (Batches)', img: 'production',
    desc: 'Track each production batch through its stages with quality checks.',
    steps: [
      'Go to <b>Production</b> to see all batches and their current stage.',
      'Open a batch to move it through stages and log progress.',
      'Raw material (coil, chemical, film, tape) is auto-consumed from stock when a run starts.',
    ],
    tip: 'Stock is deducted at production start, not dispatch — keep raw material updated.' },

  { key: 'runs', step: 9, title: 'Production Runs', img: 'runs',
    desc: 'Group multiple same-spec orders into one efficient run; check material before starting.',
    steps: [
      'Go to <b>Production Runs</b>. Click <b>📦 Material</b> on a run to see the raw-material requirement vs available stock.',
      'If stock is short, click <b>🛒 Create draft PO for shortage</b> to raise a purchase order.',
      'Click <b>Start</b> to begin (material is consumed), then <b>Complete</b> when done and enter actual consumption.',
    ],
    tip: 'The wastage report compares actual vs standard consumption — watch it to control losses.' },

  { key: 'qc', step: 10, title: 'Quality Control', img: 'qc',
    desc: 'Record QC checks before goods move to dispatch.',
    steps: [
      'Go to <b>Quality Control</b> to see batches awaiting QC.',
      'Pass or fail each batch with remarks.',
      'Passed batches become available for dispatch.',
    ] },

  { key: 'stock', step: 11, title: 'Stock / Inventory', img: 'stock',
    desc: 'Coil, chemical and consumable stock with low-stock alerts.',
    steps: [
      'Go to <b>Stock</b> to view coil, chemical and consumable inventory.',
      'Add stock-in entries when material is received.',
      'Items at or below their reorder level raise a low-stock alert on the Dashboard.',
    ],
    tip: 'Set sensible reorder levels so you’re warned before you run out mid-production.' },

  { key: 'procurement', step: 12, title: 'Procurement', img: 'procurement',
    desc: 'Purchase orders, vendors, and goods receipt that updates stock with cost.',
    steps: [
      'Go to <b>Procurement</b>. Click <b>+ New PO</b> to raise a purchase order to a vendor.',
      'Click <b>⚠ Suggest Reorder</b> to auto-fill a PO for everything below reorder level.',
      'When goods arrive, click <b>Receive</b> — stock increases and unit cost updates.',
      'Manage vendors under the <b>Suppliers / Vendors</b> tab.',
    ],
    tip: 'Receiving against a PO keeps your stock value and costs accurate.' },

  { key: 'dispatches', step: 13, title: 'Dispatches', img: 'dispatches',
    desc: 'Create dispatches and challans for finished, QC-passed goods.',
    steps: [
      'Go to <b>Dispatches</b> to create a dispatch for a ready batch.',
      'Enter vehicle and delivery details; generate the challan PDF.',
      'Mark the dispatch complete on delivery — the customer can be notified on WhatsApp automatically.',
    ] },

  { key: 'invoices', step: 14, title: 'Invoices', img: 'invoices',
    desc: 'Raise GST tax invoices, add IRN/e-Way bill, and track payment.',
    steps: [
      'Go to <b>Invoices</b>. Create an invoice from a dispatch or an order.',
      'Click <b>📄 PDF</b> for a professional GST tax invoice (CGST/SGST or IGST, amount in words).',
      'Use the <b>e-Invoice &amp; e-Way Bill</b> panel to add the IRN/QR and e-Way Bill number.',
      'Record payments — the balance and status update automatically.',
    ],
    tip: 'e-Invoice/e-Way needs the Pro plan; you can always enter the IRN manually from the GST portal.' },

  { key: 'receivables', step: 15, title: 'Receivables', img: 'receivables',
    desc: 'See who owes you money, aged by how overdue it is.',
    steps: [
      'Go to <b>Receivables</b> to see outstanding invoices by ageing bucket (0–30, 31–60, 61–90, 90+).',
      'Send payment reminders (SMS/WhatsApp) to customers with overdue invoices.',
      'Click an invoice to view or record a payment.',
    ],
    tip: 'Chase the 90+ bucket first — that’s the money most at risk.' },

  { key: 'reports', step: 16, title: 'Reports', img: 'reports',
    desc: 'Dashboards, MIS, reconciliation, Tally export and CSV downloads.',
    steps: [
      'Go to <b>Reports</b>. The <b>Dashboard</b> tab shows revenue trend, top customers and panel mix.',
      '<b>MIS Report</b> = monthly revenue, GST liability, production and receivables ageing.',
      '<b>Reconciliation</b> finds orders that were delivered but not / under-invoiced (revenue leak).',
      '<b>Tally Export</b> and <b>Export</b> download your data for accounting/backup.',
    ],
    tip: 'Run Reconciliation monthly to catch any order you forgot to invoice.' },

  { key: 'company', step: 17, title: 'Settings — Company', img: 'company',
    desc: 'Your company identity, logo, GST details and document prefixes.',
    steps: [
      'Go to <b>Settings → Company</b>.',
      'Upload your logo and fill GSTIN, address and bank details — these appear on PDFs.',
      'Set quotation/invoice/challan number prefixes.',
    ] },

  { key: 'notifications', step: 18, title: 'Settings — Notifications', img: 'notifications',
    desc: 'Connect Twilio to send SMS and WhatsApp alerts to customers.',
    steps: [
      'Go to <b>Settings → Notifications</b>.',
      'Paste your Twilio Account SID and Auth Token, set the From number, and enable SMS / WhatsApp.',
      'Use <b>Send Test</b> to confirm it works, then choose which events trigger a message.',
    ],
    tip: 'For WhatsApp testing use the Twilio sandbox number and have the recipient send the join code first.' },

  { key: 'billing', step: 19, title: 'Settings — Billing & Plan', img: 'billing',
    desc: 'View your subscription plan, trial status and renew / upgrade.',
    steps: [
      'Go to <b>Settings → Billing & Plan</b> to see your current plan and renewal date.',
      'Choose a plan and pay online (if enabled) to activate or upgrade.',
    ] },

  { key: 'doctemplates', step: 20, title: 'Settings — Doc Templates', img: 'doctemplates',
    desc: 'Pick the PDF design used for BOQ, Quotation and Invoice.',
    steps: [
      'Go to <b>Settings → Doc Templates</b>.',
      'Preview a template and apply it — your PDFs use the chosen design.',
    ] },

  { key: 'master', step: 21, title: 'Settings — Master Data', img: 'master',
    desc: 'Maintain your panel-type catalogue and accessories.',
    steps: [
      'Go to <b>Settings → Master Data</b>.',
      'Add/edit panel types (name, category, base price, thicknesses) and accessories.',
      'These power the line items you pick when building quotations.',
    ] },

  { key: 'users', step: 22, title: 'Settings — Users & Roles', img: 'users',
    desc: 'Add team members and control what each role can see and do.',
    steps: [
      'Go to <b>Settings → Users & Roles</b> (admins only).',
      'Click <b>+ Add User</b> to invite a team member and assign a role.',
      'Open the <b>Roles & Permissions</b> tab to tick exactly what each role can access — e.g. hide cost/margin from non-admins.',
    ],
    tip: 'Give each person the least access they need; admins always see everything.' },
]
</script>

<style scoped>
.ug-wrap { display: flex; gap: 24px; padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; align-items: flex-start; }

.ug-index { position: sticky; top: 16px; width: 210px; flex-shrink: 0; background: #fff; border: 1px solid #e2e6ec; border-radius: 12px; padding: 14px; max-height: calc(100vh - 40px); overflow-y: auto; }
.ug-index-title { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--primary); margin-bottom: 8px; }
.ug-link { display: block; font-size: 12.5px; color: #555; text-decoration: none; padding: 6px 9px; border-radius: 6px; }
.ug-link:hover { background: #f3f5f9; color: var(--primary); }
.ug-link.on { background: var(--primary-tint); color: var(--primary); font-weight: 600; }

.ug-content { flex: 1; min-width: 0; }
.ug-hero { background: linear-gradient(135deg, #1a237e, #3d5af1); color: #fff; border-radius: 14px; padding: 24px 28px; margin-bottom: 22px; }
.ug-hero h2 { margin: 0 0 6px; font-size: 22px; }
.ug-hero p { margin: 0; font-size: 13.5px; opacity: .92; line-height: 1.6; }

.ug-section { background: #fff; border: 1px solid #e2e6ec; border-radius: 12px; padding: 20px 24px; margin-bottom: 18px; scroll-margin-top: 16px; }
.ug-head { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 14px; }
.ug-num { width: 30px; height: 30px; flex-shrink: 0; background: var(--primary); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; }
.ug-head h3 { margin: 2px 0 3px; font-size: 17px; color: var(--primary); }
.ug-desc { margin: 0; font-size: 13px; color: #667085; }

.ug-body { display: grid; grid-template-columns: 1fr 1.1fr; gap: 20px; align-items: start; }
.ug-steps { margin: 0; padding-left: 20px; }
.ug-steps li { font-size: 13.5px; color: #333; margin: 9px 0; line-height: 1.55; }
.ug-shot { margin: 0; }
.ug-shot img { width: 100%; border: 1px solid #e2e6ec; border-radius: 10px; cursor: zoom-in; box-shadow: 0 4px 14px rgba(0,0,0,.08); }
.ug-shot figcaption { text-align: center; font-size: 11px; color: #aaa; margin-top: 5px; }

.ug-tip { margin-top: 14px; background: #fff8e1; border: 1px solid #ffe082; color: #6d4c00; border-radius: 8px; padding: 10px 14px; font-size: 12.5px; }
.ug-foot { text-align: center; color: #aaa; font-size: 13px; padding: 20px; }

.ug-zoom { position: fixed; inset: 0; background: rgba(0,0,0,.8); display: flex; align-items: center; justify-content: center; z-index: 3000; padding: 24px; cursor: zoom-out; }
.ug-zoom img { max-width: 95%; max-height: 95%; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,.5); }

@media (max-width: 1000px) {
  .ug-index { display: none; }
  .ug-body { grid-template-columns: 1fr; }
}
</style>
