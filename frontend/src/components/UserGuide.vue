<template>
  <div class="ug-wrap">
    <!-- Left index -->
    <aside class="ug-index">
      <div class="ug-index-title">User Guide</div>
      <a href="#g-intro" :class="['ug-link', { on: activeKey === 'intro' }]" @click="activeKey = 'intro'">Getting Started</a>
      <a v-for="s in sections" :key="s.key" :href="'#g-' + s.key"
         :class="['ug-link', { on: activeKey === s.key }]" @click="activeKey = s.key">
        {{ s.step }}. {{ s.title }}
      </a>
    </aside>

    <!-- Content -->
    <div class="ug-content">
      <div id="g-intro" class="ug-hero">
        <h2>How to use PanelOS — complete walkthrough</h2>
        <p>This guide explains every module in detail, with a real example that runs through
           the whole system. We follow one order from start to finish:</p>
        <div class="flow">
          <span>Lead</span><i>→</i><span>Quotation</span><i>→</i><span>Order</span><i>→</i>
          <span>Production</span><i>→</i><span>Dispatch</span><i>→</i><span>Invoice</span><i>→</i><span>Payment</span>
        </div>
        <div class="story-box">
          <b>📖 Our running example:</b> <i>Shree Cold Storage</i> enquires for <b>200 roof panels (50&nbsp;mm, PUF)</b>.
          In each module below you'll see how this single order moves forward — so by the end you've seen the full cycle.
        </div>
      </div>

      <section v-for="s in sections" :key="s.key" :id="'g-' + s.key" class="ug-section">
        <div class="ug-head">
          <span class="ug-num">{{ s.step }}</span>
          <div>
            <h3>{{ s.title }}</h3>
            <p class="ug-desc">{{ s.desc }}</p>
          </div>
        </div>

        <div class="ug-what"><b>What it’s for:</b> {{ s.what }}</div>

        <div class="ug-body">
          <div class="ug-left">
            <div class="ug-label">Step by step</div>
            <ol class="ug-steps">
              <li v-for="(st, i) in s.steps" :key="i" v-html="st"></li>
            </ol>
          </div>
          <figure class="ug-shot" v-if="s.img">
            <img :src="'/guide/' + s.img + '.png'" :alt="s.title" loading="lazy" @click="zoom = '/guide/' + s.img + '.png'" />
            <figcaption>📷 Actual system screen · click to enlarge</figcaption>
          </figure>
        </div>

        <div class="ug-case">
          <div class="ug-case-title">📖 Example — Shree Cold Storage order</div>
          <p class="ug-case-scn">{{ s.usecase.scenario }}</p>
          <ol class="ug-case-steps">
            <li v-for="(w, i) in s.usecase.walk" :key="i" v-html="w"></li>
          </ol>
        </div>

        <div v-if="s.tip" class="ug-tip">💡 <b>Tip:</b> {{ s.tip }}</div>
      </section>

      <div class="ug-foot">That’s the full cycle — from enquiry to money in the bank. Need help? Contact support.</div>
    </div>

    <!-- Zoom overlay -->
    <div v-if="zoom" class="ug-zoom" @click="zoom = null"><img :src="zoom" alt="screenshot" /></div>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const zoom = ref(null)
const activeKey = ref('intro')

const sections = [
  {
    key: 'dashboard', step: 1, title: 'Dashboard', img: 'dashboard',
    desc: 'Your daily command centre — one screen to know exactly what needs attention today.',
    what: 'The Dashboard summarises the whole business in live numbers so you never have to dig through modules to know the state of things. It pulls open quotations, active orders, items in production, pending dispatches, outstanding money, low-stock warnings and overdue invoices into one view.',
    steps: [
      'Click <b>Dashboard</b> at the very top of the sidebar (it’s also the screen you land on after login).',
      'Read the <b>KPI cards</b> left to right: Open Quotations, Active Orders, In Production, Pending Dispatch, Outstanding (₹). Each card shows a trend line like “+3 this week”.',
      'Look at the <b>Alerts</b> panel — it lists low-stock items and overdue invoices. <b>Click any alert</b> to jump straight to that screen.',
      'Scan the <b>Order-to-Cash Pipeline</b> — it shows how many records are sitting at each stage (Quotations: Draft/Sent/Accepted, Orders: Pending/Producing/Done, etc.).',
      'Check the <b>Receivables Aging</b> bar to see how much money is current vs 30/60/90+ days overdue.',
      'Watch the <b>red number badges</b> on sidebar items (Leads, Production Plan, Receivables) — they tell you what is overdue or needs action.',
    ],
    usecase: {
      scenario: 'It’s 9 AM. The owner of UMA opens PanelOS to plan the day before the Shree Cold Storage enquiry even comes in.',
      walk: [
        'He opens the <b>Dashboard</b> and sees <b>2 chemicals below reorder level</b> in Alerts — he notes to raise a PO.',
        'He sees <b>7 invoices overdue</b> — he plans to send reminders later from Receivables.',
        'The pipeline shows <b>9 accepted quotations</b> ready to become orders — good, the sales team is active.',
        'Reassured he knows the day’s priorities, he’s ready when the new Shree Cold Storage call comes in.',
      ],
    },
    tip: 'Make the Dashboard your first stop every morning. If a sidebar item has a red badge, clear it that day.',
  },

  {
    key: 'leads', step: 2, title: 'Leads (Enquiries)', img: 'leads',
    desc: 'Capture every enquiry, never miss a follow-up, and convert hot leads into quotations.',
    what: 'A Lead is any enquiry that hasn’t become a quotation yet. This module stops enquiries from being lost in WhatsApp and diaries — every call/visit is logged, every lead has an owner and a follow-up date, and converting a lead pre-fills the quotation so you don’t retype anything.',
    steps: [
      'Open <b>Leads</b>. Click <b>+ Add Lead</b>.',
      'Fill the enquiry: customer name, phone, requirement (e.g. “200 roof panels 50 mm”), <b>source</b> (Referral / Website / Cold call) and assign an owner.',
      'Set a <b>Next follow-up date</b>. Leads due today/overdue show a <b>red badge</b> on the Leads menu so nothing slips.',
      'Open the lead to log <b>activities</b> — record each call, site visit, quotation discussion with a note and date.',
      'Move the lead through statuses: <b>New → Contacted → Quoted → Won / Lost</b> (if Lost, capture the reason for insights).',
      'When the customer is ready to be quoted, click <b>Convert</b> — the system creates a Customer (if new) and opens a Quotation with the details carried over.',
    ],
    usecase: {
      scenario: 'Shree Cold Storage calls UMA asking for a price on 200 roof panels.',
      walk: [
        'The sales person opens <b>Leads → + Add Lead</b>: name “Shree Cold Storage”, phone, requirement “200 roof panels, 50 mm PUF”, source “Referral”.',
        'They set <b>Next follow-up = tomorrow</b> and save. The Leads menu now shows a badge.',
        'After a site visit they open the lead and add an activity: “Visited site, confirmed 50 mm roof panels, needs delivery in 3 weeks.”',
        'Customer agrees to get a formal quote → they click <b>Convert</b>. A new Customer “Shree Cold Storage” is created and a Quotation opens, ready to price.',
      ],
    },
    tip: 'The red number on “Leads” = follow-ups due today. Clear it daily — that discipline alone wins more orders.',
  },

  {
    key: 'customers', step: 3, title: 'Customers', img: 'customers',
    desc: 'Your customer directory with a deep 360° profile and credit control for every buyer.',
    what: 'Every buyer lives here with their GST details, addresses, credit limit and payment terms. The 360° profile shows their entire history — all quotations, orders, invoices, payments and how often they re-order — so you instantly know how valuable and how reliable a customer is.',
    steps: [
      'Open <b>Customers</b> to see all buyers; use the search box to find one fast.',
      'Click <b>+ Add Customer</b> and fill name, type, contact, <b>GSTIN</b>, full address with state, <b>credit limit</b> and <b>payment terms (days)</b>.',
      'Click any customer <b>name</b> to open their <b>360° profile</b>: lifetime value, KPIs, RFM segment (New / Loyal / Champion / At-Risk), monthly trend, and every related record.',
      'Set the <b>Credit Limit</b> carefully — the system will block new orders that push the customer’s outstanding past this limit (an admin can override).',
      'Use the profile’s repeat-order frequency to decide who to call for the next season’s order.',
    ],
    usecase: {
      scenario: 'The lead was converted, so “Shree Cold Storage” now exists as a customer — we complete their details.',
      walk: [
        'Open <b>Customers</b>, search “Shree”, open the record.',
        'Add their <b>GSTIN</b> and <b>state = Gujarat</b> (this decides CGST/SGST vs IGST later), and set a <b>credit limit of ₹5,00,000</b>.',
        'Set <b>payment terms = 30 days</b>.',
        'Open their 360° profile — for now it’s a “New” customer with one open quotation. Over time it will fill with their order history.',
      ],
    },
    tip: 'Always fill GSTIN + state correctly — they drive the tax type and the figures on every invoice.',
  },

  {
    key: 'quotations', step: 4, title: 'Quotations', img: 'quotations',
    desc: 'Build a priced quotation, send it, get it accepted, and convert it into an order.',
    what: 'The Quotation is your formal price offer. You add panel line items with thickness, skin and sizes; rates and totals (with GST) calculate automatically. You can edit rates inline, see the financial summary update live, send a professional PDF, and once accepted, turn it into an order with one click.',
    steps: [
      'Open <b>Quotations</b> → click <b>+ New Quote</b> (top-right). Choose the customer (or it’s pre-filled if you converted a lead).',
      'Add a <b>line item</b>: pick panel type (e.g. Roof Panel), thickness (50 mm), skin (PPGI), then enter sizes and quantities. Area (SQM) and amount calculate automatically.',
      'On the detail page, edit the <b>rate column inline</b> — the <b>financial summary updates live</b> (subtotal, GST, total) in the sticky bar.',
      'Add accessories, transport or discount if needed. Use <b>Edit</b> to add/change items.',
      'Click <b>Download PDF</b> to send a professional proforma to the customer.',
      'When the customer approves: click <b>Send</b> → <b>Accept</b> → <b>Create Order</b>. (You can also <b>Revise</b> to keep a version, or <b>Duplicate</b> for a similar deal.)',
    ],
    usecase: {
      scenario: 'We price Shree Cold Storage’s 200 roof panels and send the quote.',
      walk: [
        'From the converted lead, the Quotation opens with “Shree Cold Storage” already selected.',
        'Add a line: <b>Roof Panel, 50 mm, PPGI skin</b>; enter the size list totalling <b>200 panels</b>. The system computes total SQM and amount.',
        'On the detail page they fine-tune the <b>rate to ₹1,050/SQM</b> inline; the summary instantly shows subtotal, 18% GST and grand total.',
        'They <b>Download the PDF</b> and email it. Two days later the customer approves → <b>Send → Accept → Create Order</b>.',
      ],
    },
    tip: 'Use Revise (not edit) when a customer negotiates — it keeps the old version so you have an audit trail of price changes.',
  },

  {
    key: 'boq', step: 5, title: 'BOQ Register', img: 'boq',
    desc: 'A rate-less Bill of Quantities stage — lock the cutting list first, add prices later.',
    what: 'On big projects the customer often shares a detailed cutting list before pricing is agreed. The BOQ lets you capture all those sizes and quantities as a distinct stage (no rates yet). When pricing is finalised you convert the BOQ into a Quotation in one click — no re-entry.',
    steps: [
      'Open <b>BOQ Register</b> to see all BOQs and their status.',
      'Create a BOQ when you have the sizes/quantities but rates aren’t finalised — enter the full cutting list.',
      'Keep refining quantities as the project scope firms up.',
      'When pricing is agreed, click <b>Convert to Quotation</b> — all line items move across and you just add rates.',
    ],
    usecase: {
      scenario: 'Suppose Shree Cold Storage’s order was a large cold-room project where sizes came first.',
      walk: [
        'The engineer enters the full panel cutting list as a <b>BOQ</b> (wall + roof panels, dozens of sizes) without any rates.',
        'After two rounds of measurement changes the BOQ is finalised.',
        'Commercial team clicks <b>Convert to Quotation</b>, adds the agreed ₹/SQM rates, and sends it — no sizes were retyped.',
      ],
    },
    tip: 'Use BOQ for project orders; use a direct Quotation for simple repeat orders.',
  },

  {
    key: 'orders', step: 6, title: 'Orders', img: 'orders',
    desc: 'Confirmed orders, ready to be produced, dispatched and invoiced.',
    what: 'An Order is a confirmed, accepted quotation. It’s the single source of truth that production, dispatch and invoicing all refer back to. Creating an order also runs a credit-limit check on the customer to protect you from over-exposure.',
    steps: [
      'Orders are created automatically when you click <b>Create Order</b> on an accepted quotation.',
      'Open <b>Orders</b> to see all confirmed orders and their status (Pending → In Production → Completed).',
      'Open an order to view items, sizes, total SQM and the expected delivery date.',
      'If the customer is over their credit limit, order creation is <b>blocked</b> with a clear message — an admin can override with approval.',
      'From here the order flows into <b>Production Plan</b> and <b>Production Runs</b>.',
    ],
    usecase: {
      scenario: 'Shree Cold Storage’s accepted quotation becomes a firm order.',
      walk: [
        'On the accepted quotation, the sales person clicks <b>Create Order</b>.',
        'The system checks credit: outstanding ₹0 + this order ₹2.4L is well under the ₹5L limit → order is created as <b>ORD-2026-00xx</b>, status Pending.',
        'They open the order, confirm the <b>expected delivery date</b> (3 weeks out), and hand it to production.',
      ],
    },
    tip: 'If an order is blocked on credit, that’s the system protecting your cash — review the customer’s outstanding before overriding.',
  },

  {
    key: 'planner', step: 7, title: 'Production Plan', img: 'planner',
    desc: 'Smart planner that groups same-spec jobs together to cut line-changeover waste.',
    what: 'The PUF line wastes material every time you change panel specification (thickness/density/colour). The planner looks at all pending orders, groups same-spec jobs, and tells you which job to run first so similar panels run back-to-back — saving foam and coil.',
    steps: [
      'Open <b>Production Plan</b>. The system automatically groups pending orders by panel specification.',
      'Read the <b>alerts</b> — e.g. “Run this 50 mm roof job before the 80 mm wall job to avoid a changeover”.',
      'Review each suggested group of same-spec orders.',
      'Click <b>Create Run</b> on a group to turn those same-spec orders into a single production run.',
    ],
    usecase: {
      scenario: 'Shree’s 50 mm roof order arrives the same week as another customer’s 50 mm roof order.',
      walk: [
        'In <b>Production Plan</b>, the system groups <b>both 50 mm roof orders together</b> and flags: “Run these together — same spec.”',
        'It warns that an 80 mm wall order is also pending and should be run <b>after</b> the 50 mm jobs to avoid two changeovers.',
        'The supervisor clicks <b>Create Run</b> on the grouped 50 mm roof jobs — both customers’ panels will be produced in one continuous run.',
      ],
    },
    tip: 'Trust the grouping — running same-spec jobs back-to-back is the single biggest way to cut PUF-line waste.',
  },

  {
    key: 'production', step: 8, title: 'Production (Batches)', img: 'production',
    desc: 'Track each production batch through its stages with quality checkpoints.',
    what: 'A Batch is the unit of work on the shop floor. This module shows every batch and the stage it’s at, and lets you move it forward. Raw material (coil, chemical, film, tape) is automatically consumed from stock when a run starts, so your inventory stays truthful.',
    steps: [
      'Open <b>Production</b> to see all batches and their current stage.',
      'Open a batch to move it through its stages and log progress.',
      'Note that <b>raw material is auto-deducted from stock at production start</b> (not at dispatch).',
      'Once a batch finishes production it goes to <b>Quality Control</b>.',
    ],
    usecase: {
      scenario: 'Shree’s panels are now on the line.',
      walk: [
        'The grouped run’s batch appears in <b>Production</b> at the “Running” stage.',
        'When the run started, the system <b>auto-consumed</b> the required coil, polyol, isocyanate and film from stock.',
        'The supervisor advances the batch through stages as the panels are produced.',
      ],
    },
    tip: 'Because stock is deducted at production start, keep your raw-material stock entries up to date or you’ll see false shortages.',
  },

  {
    key: 'runs', step: 9, title: 'Production Runs', img: 'runs',
    desc: 'Group same-spec orders into one efficient run; check material before you start.',
    what: 'A Run bundles same-spec orders so they’re produced together. Before starting, you can see exactly what raw material the run needs versus what’s in stock — and if you’re short, raise a purchase order in one click. After completion you record actual consumption to track wastage.',
    steps: [
      'Open <b>Production Runs</b>. Click <b>📦 Material</b> on a run to see the <b>raw-material requirement vs available stock</b> (coil, polyol, isocyanate, film, tape) with shortages highlighted.',
      'If anything is short, click <b>🛒 Create draft PO for shortage</b> — a purchase order is drafted for the missing quantities.',
      'Click <b>▶ Start</b> to begin the run — material is consumed and batches move into production. (If stock is short you can still start with an override.)',
      'When finished, click <b>✓ Complete</b> and enter the <b>actual material consumed</b> — the system reconciles stock and records wastage.',
      'Open the <b>Wastage Report</b> to compare actual vs standard consumption over time.',
    ],
    usecase: {
      scenario: 'Before starting Shree’s grouped roof run, the supervisor checks material.',
      walk: [
        'He clicks <b>📦 Material</b> on the run — it shows the run needs 1,200 kg coil and the foam chemicals; coil is fine but <b>polyol is short by 80 kg</b>.',
        'He clicks <b>🛒 Create draft PO for shortage</b> → a PO for the polyol shortfall is drafted in Procurement.',
        'Once polyol is received he clicks <b>▶ Start</b>; material is consumed and production begins.',
        'After the run he clicks <b>✓ Complete</b>, enters actual consumption — the wastage report shows a healthy 2% over standard.',
      ],
    },
    tip: 'Always check Material before starting a run. Catching a shortage now is far cheaper than stopping the line mid-run.',
  },

  {
    key: 'qc', step: 10, title: 'Quality Control', img: 'qc',
    desc: 'Record QC checks so only good panels move to dispatch.',
    what: 'QC is the gate between production and dispatch. Each finished batch is inspected and marked pass or fail with remarks; only passed batches can be dispatched, protecting you from sending defective goods and the disputes that follow.',
    steps: [
      'Open <b>Quality Control</b> to see batches awaiting inspection.',
      'Inspect each batch and mark it <b>Pass</b> or <b>Fail</b> with remarks (e.g. bonding, dimensions, finish).',
      'Failed batches are held back; passed batches become available for dispatch.',
    ],
    usecase: {
      scenario: 'Shree’s produced panels reach QC.',
      walk: [
        'The QC person opens <b>Quality Control</b>, finds Shree’s batch in “QC pending”.',
        'They check bonding strength and panel dimensions, then mark the batch <b>Pass</b> with a remark “Dimensions and finish OK”.',
        'The batch is now ready to be dispatched.',
      ],
    },
    tip: 'Capture a clear remark on every QC pass/fail — it’s your defence if a customer later raises a quality dispute.',
  },

  {
    key: 'stock', step: 11, title: 'Stock / Inventory', img: 'stock',
    desc: 'Coil, chemical and consumable stock with automatic low-stock alerts.',
    what: 'This is your raw-material inventory: coil (by panel type), chemicals (polyol, isocyanate, etc.) and consumables (film, tape). Stock goes down automatically when runs start and up when you receive purchase orders. Items at or below their reorder level raise an alert on the Dashboard.',
    steps: [
      'Open <b>Stock</b> to view coil, chemical and consumable inventory with current quantities.',
      'Add a <b>stock-in</b> entry when material is received outside a PO, or receive it through Procurement to also capture cost.',
      'Set a sensible <b>reorder level</b> on each item — at/below it, a low-stock alert appears on the Dashboard.',
      'Watch chemical <b>expiry dates</b> — expiring items are flagged too.',
    ],
    usecase: {
      scenario: 'The polyol that ran short for Shree’s run needs restocking.',
      walk: [
        'After the Dashboard flagged “chemical below reorder level”, the store keeper opens <b>Stock</b>.',
        'They confirm <b>polyol is below its reorder level</b>.',
        'They raise a PO from Procurement; when it arrives and is received, polyol stock goes back up automatically.',
      ],
    },
    tip: 'Set reorder levels a little above your worst-case run consumption so you’re warned before a run is blocked.',
  },

  {
    key: 'procurement', step: 12, title: 'Procurement', img: 'procurement',
    desc: 'Purchase orders, vendors, and goods receipt that updates stock with cost.',
    what: 'Procurement manages buying raw material. You raise purchase orders to vendors, and when goods arrive you “receive” them — which increases stock and updates the unit cost. It can also auto-suggest a PO for everything below reorder level.',
    steps: [
      'Open <b>Procurement</b>. Click <b>+ New PO</b>, pick a vendor, add items and quantities, set GST %.',
      'Or click <b>⚠ Suggest Reorder</b> — it pre-fills a PO with every item that’s below its reorder level.',
      'Save the PO and send it to the vendor.',
      'When goods arrive, open the PO and click <b>Receive</b> — enter received quantity and cost; <b>stock increases and unit cost updates</b>.',
      'Manage vendors under the <b>Suppliers / Vendors</b> tab.',
    ],
    usecase: {
      scenario: 'We buy the polyol that Shree’s run needed.',
      walk: [
        'The draft PO created from the run’s shortage is already in <b>Procurement</b> — they assign the chemical vendor and send it.',
        'Two days later the polyol arrives. They open the PO → <b>Receive</b> → enter 200 kg at ₹X/kg.',
        '<b>Polyol stock rises by 200 kg</b> and its unit cost updates, so stock value stays accurate.',
      ],
    },
    tip: 'Always receive against the PO (don’t just add stock manually) — that’s what keeps your stock value and costs correct.',
  },

  {
    key: 'dispatches', step: 13, title: 'Dispatches', img: 'dispatches',
    desc: 'Create dispatches and challans for finished, QC-passed goods.',
    what: 'Dispatch is how goods leave your factory. You create a dispatch for a QC-passed batch, record vehicle and delivery details, generate the delivery challan PDF, and mark it delivered. The customer can be notified automatically on WhatsApp.',
    steps: [
      'Open <b>Dispatches</b> and create a dispatch for a QC-passed batch.',
      'Enter vehicle number and delivery details; generate the <b>challan PDF</b>.',
      'Mark the dispatch <b>complete</b> on delivery.',
      'If WhatsApp is configured, the customer gets an automatic “your goods have been dispatched” message.',
    ],
    usecase: {
      scenario: 'Shree’s passed panels are loaded for delivery.',
      walk: [
        'The dispatch clerk opens <b>Dispatches</b>, creates a dispatch for Shree’s batch, and enters the truck number.',
        'They generate the <b>challan PDF</b> and hand it to the driver.',
        'On marking the dispatch complete, Shree Cold Storage automatically receives a <b>WhatsApp</b>: “🚚 Your goods have been dispatched, Dispatch No …”.',
      ],
    },
    tip: 'Ask customers to inspect on delivery and report damage within 24 hrs — the WhatsApp message already says this.',
  },

  {
    key: 'invoices', step: 14, title: 'Invoices', img: 'invoices',
    desc: 'Raise GST tax invoices, add IRN / e-Way bill, and track payment.',
    what: 'The Invoice is your tax document and your claim for payment. Create it from a dispatch or order; it produces a professional GST invoice (CGST/SGST or IGST, amount in words). You can add the IRN/QR and e-Way Bill, then record payments as they come in — the balance and status update automatically.',
    steps: [
      'Open <b>Invoices</b>. Create an invoice <b>from a dispatch</b> (or from an order).',
      'Click <b>📄 PDF</b> to get the professional GST tax invoice — correct CGST/SGST vs IGST based on the customer’s state, with amount in words.',
      'Use the <b>e-Invoice &amp; e-Way Bill</b> panel to enter the IRN, ACK number and QR (from the GST portal) and the e-Way Bill number.',
      'Send the invoice; then <b>record payments</b> as they arrive — balance due and status (sent → partly paid → paid) update automatically.',
    ],
    usecase: {
      scenario: 'We bill Shree Cold Storage for the delivered panels.',
      walk: [
        'From Shree’s completed dispatch, the accountant clicks <b>create invoice</b> → <b>INV-2026-00xx</b> is generated.',
        'They open the <b>📄 PDF</b> — since Shree is in Gujarat (same state), it shows <b>CGST 9% + SGST 9%</b>, total in words.',
        'They generate the IRN on the GST portal and paste it into the <b>e-Invoice</b> panel; they add the <b>e-Way Bill</b> number for transport.',
        'A week later Shree pays ₹2,00,000 — the accountant <b>records the payment</b>; the invoice now shows a balance of the remainder.',
      ],
    },
    tip: 'e-Invoice/e-Way auto-generation needs the Pro plan + a GSP, but you can always enter the IRN manually from the GST portal on any plan.',
  },

  {
    key: 'receivables', step: 15, title: 'Receivables', img: 'receivables',
    desc: 'See who owes you money, aged by how overdue it is, and chase it.',
    what: 'Receivables is your collections cockpit. It lists every unpaid invoice grouped by how overdue it is (0–30 / 31–60 / 61–90 / 90+ days) so you chase the riskiest money first, and lets you send payment reminders by SMS/WhatsApp.',
    steps: [
      'Open <b>Receivables</b> to see outstanding invoices in ageing buckets.',
      'Start with the <b>90+ days</b> bucket — that money is most at risk.',
      'Send a <b>payment reminder</b> (SMS/WhatsApp) to customers with overdue invoices.',
      'Click an invoice to view details or record a payment.',
    ],
    usecase: {
      scenario: 'Shree’s invoice still has a balance after 35 days.',
      walk: [
        'The accountant opens <b>Receivables</b> and finds Shree’s remaining balance now in the <b>31–60 day</b> bucket.',
        'They click <b>send reminder</b> — Shree gets a WhatsApp: “Invoice INV-…  (₹…) is overdue, please arrange payment.”',
        'Shree clears the balance; the accountant records it and the invoice flips to <b>Paid</b>.',
      ],
    },
    tip: 'Chase the 90+ bucket first every week — a 10-minute reminder routine dramatically improves cash flow.',
  },

  {
    key: 'reports', step: 16, title: 'Reports', img: 'reports',
    desc: 'Dashboards, MIS, reconciliation, Tally export and CSV downloads.',
    what: 'Reports turn your day-to-day data into decisions. It has visual dashboards (revenue trend, top customers, panel mix), an owner-level MIS, a reconciliation that finds un-invoiced orders (revenue leak), and exports for accounting (Tally) and analysis (CSV/Excel).',
    steps: [
      'Open <b>Reports</b>. The <b>Dashboard</b> tab shows monthly revenue trend, top customers and panel-type mix.',
      'Open <b>MIS Report</b> for a monthly summary: revenue invoiced vs collected, GST liability, production SQM, and receivables ageing.',
      'Open <b>Reconciliation</b> to find orders that were delivered but <b>not / under-invoiced</b> — your “revenue leak”.',
      'Use <b>Tally Export</b> (XML/CSV) for your accountant, and the <b>Export</b> tab to download customers/quotations/orders/invoices as CSV.',
    ],
    usecase: {
      scenario: 'Month-end review at UMA.',
      walk: [
        'The owner opens <b>Reports → MIS</b> and sees the month’s invoiced, collected and GST payable at a glance.',
        'He opens <b>Reconciliation</b> and spots <b>2 delivered orders that were never invoiced</b> — caught before money was lost. (Shree’s order shows correctly as fully invoiced.)',
        'He runs <b>Tally Export</b> and hands the XML to his accountant for GST filing.',
      ],
    },
    tip: 'Run Reconciliation every month — it routinely catches an order someone forgot to invoice.',
  },

  {
    key: 'company', step: 17, title: 'Settings — Company', img: 'company',
    desc: 'Your company identity, logo, GST details and document number prefixes.',
    what: 'This is your business’s identity that appears on every PDF. Set it once: logo, GSTIN, address, bank details and the prefixes used for quotation/invoice/challan numbers.',
    steps: [
      'Open <b>Settings → Company</b>.',
      'Upload your <b>logo</b> and fill GSTIN, address and bank details — these print on quotations, invoices and challans.',
      'Set your number <b>prefixes</b> (e.g. SCP for quotations, INV for invoices).',
      'Save — every new document now carries your branding.',
    ],
    usecase: {
      scenario: 'UMA sets up its identity before sending the first document.',
      walk: [
        'The admin opens <b>Settings → Company</b>, uploads the UMA logo and fills GSTIN + Vadodara address.',
        'They set prefix “SCP” for quotations and “INV” for invoices.',
        'Now Shree Cold Storage’s quotation and invoice both show the UMA logo and the correct GST details.',
      ],
    },
    tip: 'Do this first — everything you send to customers reflects what you set here.',
  },

  {
    key: 'notifications', step: 18, title: 'Settings — Notifications', img: 'notifications',
    desc: 'Connect Twilio to send SMS and WhatsApp alerts to customers automatically.',
    what: 'This connects your messaging. Add your Twilio credentials once, enable SMS and/or WhatsApp, test them, and choose which events trigger a message (order confirmed, dispatch done, payment due/overdue).',
    steps: [
      'Open <b>Settings → Notifications</b>.',
      'Paste your Twilio <b>Account SID</b> and <b>Auth Token</b>, set the <b>From number</b>, and enable SMS / WhatsApp.',
      'Click <b>Send Test</b> to confirm a message actually arrives.',
      'Tick which events should notify customers (e.g. Dispatch done, Payment due).',
    ],
    usecase: {
      scenario: 'UMA wants Shree (and all customers) to get automatic dispatch updates.',
      walk: [
        'The admin opens <b>Settings → Notifications</b>, pastes the Twilio keys and enables WhatsApp.',
        'They send a <b>test WhatsApp</b> to their own number — it arrives. ✓',
        'They tick “Dispatch done” — so when Shree’s panels are dispatched, Shree gets the WhatsApp automatically (as seen in step 13).',
      ],
    },
    tip: 'For WhatsApp testing, use the Twilio sandbox number and have the recipient send the join code once first.',
  },

  {
    key: 'billing', step: 19, title: 'Settings — Billing & Plan', img: 'billing',
    desc: 'View your PanelOS subscription, trial status, and renew / upgrade.',
    what: 'This is your own subscription to PanelOS — your current plan, trial/renewal date, and a way to pay online to activate or upgrade.',
    steps: [
      'Open <b>Settings → Billing & Plan</b> to see your current plan and renewal/trial date.',
      'Compare plans (Starter / Growth / Pro) and their limits.',
      'Click a plan to pay online (if enabled) and activate or upgrade instantly.',
    ],
    usecase: {
      scenario: 'UMA’s 14-day trial is ending.',
      walk: [
        'The admin opens <b>Settings → Billing & Plan</b> and sees “Trial ends in 2 days”.',
        'They pick the <b>Growth</b> plan and pay online via Razorpay.',
        'The account activates instantly and full access continues with no interruption.',
      ],
    },
    tip: 'Upgrade to Pro if you need e-Invoice/e-Way auto-generation and unlimited users.',
  },

  {
    key: 'doctemplates', step: 20, title: 'Settings — Doc Templates', img: 'doctemplates',
    desc: 'Pick the PDF design used for BOQ, Quotation and Invoice.',
    what: 'Choose how your documents look. Preview the available templates and apply one; all your PDFs then use that design.',
    steps: [
      'Open <b>Settings → Doc Templates</b>.',
      'Preview a template for each document type (Quotation / BOQ / Invoice).',
      'Click <b>Apply</b> — your PDFs now use the chosen design.',
    ],
    usecase: {
      scenario: 'UMA wants its invoices to look professional for Shree.',
      walk: [
        'The admin opens <b>Settings → Doc Templates</b>, previews the invoice design.',
        'They apply the professional template.',
        'Shree’s invoice PDF now uses that clean, branded layout.',
      ],
    },
    tip: 'Set this once at the start so every customer sees a consistent, professional document.',
  },

  {
    key: 'master', step: 21, title: 'Settings — Master Data', img: 'master',
    desc: 'Maintain your panel-type catalogue and accessories.',
    what: 'Master Data is the catalogue that powers your quotations. Define your panel types (name, category, base price, available thicknesses) and accessories once, and they appear as choices whenever you build a quote.',
    steps: [
      'Open <b>Settings → Master Data</b>.',
      'Add/edit <b>panel types</b>: name, category (roof/wall/etc.), base price and available thicknesses.',
      'Add <b>accessories</b> (flashings, screws, etc.) that can be added to quotes.',
      'These become the dropdown choices when you add quotation line items.',
    ],
    usecase: {
      scenario: 'Before quoting Shree, “Roof Panel 50 mm” must exist in the catalogue.',
      walk: [
        'The admin opens <b>Settings → Master Data</b> and confirms <b>Roof Panel</b> exists with 50 mm as an available thickness and a base price.',
        'Because it’s in the catalogue, the sales person could pick it instantly while building Shree’s quotation in step 4.',
      ],
    },
    tip: 'Keep base prices roughly current here — they pre-fill quotation rates and save typing.',
  },

  {
    key: 'users', step: 22, title: 'Settings — Users & Roles', img: 'users',
    desc: 'Add team members and control exactly what each role can see and do.',
    what: 'Users & Roles is your access control. Invite team members, give each a role, and use the permission matrix to decide precisely what each role can access — for example, hide cost and margin from the sales team while admins see everything.',
    steps: [
      'Open <b>Settings → Users & Roles</b> (admins only).',
      'Click <b>+ Add User</b>, enter their details and assign a role (Sales / Production / Accounts / Viewer / Admin).',
      'Open the <b>Roles & Permissions</b> tab — tick exactly what each role can access (e.g. uncheck <b>costing.view</b> to hide cost/margin).',
      'Save — each person now sees only what their role allows.',
    ],
    usecase: {
      scenario: 'UMA adds a sales person but doesn’t want them to see costs.',
      walk: [
        'The admin opens <b>Settings → Users & Roles → + Add User</b> and creates the sales person with the <b>Sales</b> role.',
        'In <b>Roles & Permissions</b> they uncheck <b>costing.view</b> for Sales.',
        'Now that sales person can quote Shree and others but cannot see cost or margin figures.',
      ],
    },
    tip: 'Give each person the least access they need; admins always see everything regardless of the matrix.',
  },
]
</script>

<style scoped>
.ug-wrap { display: flex; gap: 24px; padding: 24px 32px 48px; max-width: 1600px; margin: 0 auto; align-items: flex-start; }

.ug-index { position: sticky; top: 16px; width: 220px; flex-shrink: 0; background: #fff; border: 1px solid #e2e6ec; border-radius: 12px; padding: 14px; max-height: calc(100vh - 40px); overflow-y: auto; }
.ug-index-title { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--primary); margin-bottom: 8px; }
.ug-link { display: block; font-size: 12.5px; color: #555; text-decoration: none; padding: 6px 9px; border-radius: 6px; }
.ug-link:hover { background: #f3f5f9; color: var(--primary); }
.ug-link.on { background: var(--primary-tint); color: var(--primary); font-weight: 600; }

.ug-content { flex: 1; min-width: 0; }
.ug-hero { background: linear-gradient(135deg, #1a237e, #3d5af1); color: #fff; border-radius: 14px; padding: 24px 28px; margin-bottom: 22px; scroll-margin-top: 16px; }
.ug-hero h2 { margin: 0 0 6px; font-size: 22px; }
.ug-hero p { margin: 0 0 12px; font-size: 13.5px; opacity: .92; line-height: 1.6; }
.flow { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-bottom: 14px; }
.flow span { background: rgba(255,255,255,.15); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.flow i { opacity: .7; font-style: normal; }
.story-box { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.25); border-radius: 10px; padding: 12px 16px; font-size: 13px; line-height: 1.6; }

.ug-section { background: #fff; border: 1px solid #e2e6ec; border-radius: 12px; padding: 20px 24px; margin-bottom: 18px; scroll-margin-top: 16px; }
.ug-head { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 12px; }
.ug-num { width: 30px; height: 30px; flex-shrink: 0; background: var(--primary); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; }
.ug-head h3 { margin: 2px 0 3px; font-size: 18px; color: var(--primary); }
.ug-desc { margin: 0; font-size: 13px; color: #667085; }

.ug-what { background: #f8fafc; border-left: 3px solid var(--primary); border-radius: 0 8px 8px 0; padding: 10px 14px; font-size: 13px; color: #333; line-height: 1.6; margin-bottom: 16px; }

.ug-body { display: grid; grid-template-columns: 1fr 1.05fr; gap: 20px; align-items: start; margin-bottom: 16px; }
.ug-label { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #8a93a0; margin-bottom: 6px; }
.ug-steps { margin: 0; padding-left: 20px; }
.ug-steps li { font-size: 13.5px; color: #333; margin: 9px 0; line-height: 1.55; }
.ug-shot { margin: 0; position: sticky; top: 16px; }
.ug-shot img { width: 100%; border: 1px solid #e2e6ec; border-radius: 10px; cursor: zoom-in; box-shadow: 0 4px 14px rgba(0,0,0,.08); }
.ug-shot figcaption { text-align: center; font-size: 11px; color: #aaa; margin-top: 5px; }

.ug-case { background: #fffaf0; border: 1px solid #ffe0b2; border-radius: 10px; padding: 14px 16px; }
.ug-case-title { font-size: 13px; font-weight: 800; color: #b5740a; margin-bottom: 6px; }
.ug-case-scn { font-size: 13px; color: #6d4c00; font-style: italic; margin: 0 0 8px; }
.ug-case-steps { margin: 0; padding-left: 20px; }
.ug-case-steps li { font-size: 13px; color: #5b4300; margin: 6px 0; line-height: 1.55; }

.ug-tip { margin-top: 14px; background: #fff8e1; border: 1px solid #ffe082; color: #6d4c00; border-radius: 8px; padding: 10px 14px; font-size: 12.5px; }
.ug-foot { text-align: center; color: #aaa; font-size: 13px; padding: 20px; }

.ug-zoom { position: fixed; inset: 0; background: rgba(0,0,0,.8); display: flex; align-items: center; justify-content: center; z-index: 3000; padding: 24px; cursor: zoom-out; }
.ug-zoom img { max-width: 95%; max-height: 95%; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,.5); }

@media (max-width: 1000px) {
  .ug-index { display: none; }
  .ug-body { grid-template-columns: 1fr; }
  .ug-shot { position: static; }
}
</style>
