<template>
  <div class="lp" :class="{ scrolled }">
    <!-- ══ NAV ══ -->
    <header class="nav">
      <div class="lp-wrap nav-in">
        <a class="brand" href="#top" @click.prevent="scrollTo('top')">
          <span class="mark">P</span>
          <span class="bn">PanelOS<small>by Digital Web Weaver</small></span>
        </a>
        <nav class="nav-links" :class="{ open: menuOpen }">
          <a href="#why" @click.prevent="scrollTo('why')">Why PanelOS</a>
          <a href="#features" @click.prevent="scrollTo('features')">Features</a>
          <a href="#how" @click.prevent="scrollTo('how')">How it works</a>
          <a href="#pricing" @click.prevent="scrollTo('pricing')">Pricing</a>
          <a href="#faq" @click.prevent="scrollTo('faq')">FAQ</a>
        </nav>
        <div class="nav-cta">
          <button class="link-btn" @click="$emit('sign-in')">Sign in</button>
          <button class="btn btn-primary sm" @click="$emit('start-trial')">Start free trial</button>
          <button class="burger" @click="menuOpen = !menuOpen" :aria-expanded="menuOpen" aria-label="Menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>
        </div>
      </div>
    </header>

    <!-- ══ HERO ══ -->
    <section class="hero" id="top">
      <div class="hero-bg"><span class="blob b1"></span><span class="blob b2"></span><span class="grid-fade"></span></div>
      <div class="lp-wrap hero-in">
        <div class="hero-copy">
          <span class="pill anim-up">⚡ Built for PUF / PIR panel manufacturers</span>
          <h1 class="anim-up d1">Run your entire panel factory on <span class="grad">one system</span> — not 50 Excel sheets.</h1>
          <p class="sub anim-up d2">From enquiry to dispatch to payment — quotations, BOQ, production, live inventory, GST e-invoicing &amp; reports, all connected. Stop re-typing. Start shipping.</p>
          <div class="hero-cta anim-up d3">
            <button class="btn btn-primary lg" @click="$emit('start-trial')">
              Start 14-day free trial
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
            <button class="btn btn-glass lg" @click="scrollTo('how')">▶ See how it works</button>
          </div>
          <div class="trust anim-up d4">
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg> No credit card</span>
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg> GST &amp; e-Invoice ready</span>
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg> Setup in minutes</span>
          </div>
        </div>
        <div class="hero-shot anim-in d2">
          <div class="frame tilt">
            <div class="fbar"><i></i><i></i><i></i><span class="url">app.panelos · Dashboard</span></div>
            <img src="/guide/dashboard.png" alt="PanelOS dashboard" loading="eager" width="900" height="560">
          </div>
          <div class="fbadge fb1 floaty"><span class="fi ok"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg></span><div>Quote in 10 min<small>not 2 hours in Excel</small></div></div>
          <div class="fbadge fb2 floaty d1"><span class="fi br"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/></svg></span><div>Live stock &amp; production<small>real-time on every screen</small></div></div>
        </div>
      </div>
      <!-- marquee -->
      <div class="marquee" aria-hidden="true">
        <div class="mq-track">
          <span v-for="(m,i) in marquee.concat(marquee)" :key="i" class="mq-chip">{{ m }}</span>
        </div>
      </div>
    </section>

    <!-- ══ PROBLEM ══ -->
    <section class="sec" id="why">
      <div class="lp-wrap center">
        <span class="eyebrow reveal">The everyday chaos</span>
        <h2 class="reveal">Still running the factory on Excel, email &amp; WhatsApp?</h2>
        <p class="lead reveal">It works… until it doesn't. Spreadsheets are stale the moment you save them, and the real status of your factory lives in 10 people's heads.</p>
        <div class="prob-grid">
          <div v-for="(p,i) in problems" :key="i" class="prob reveal" :style="{ '--d': i * 60 + 'ms' }">
            <span class="x"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M18 6L6 18M6 6l12 12"/></svg></span>
            <div><h4>{{ p.t }}</h4><p>{{ p.d }}</p></div>
          </div>
        </div>
      </div>
    </section>

    <!-- ══ COMPARISON ══ -->
    <section class="sec alt" id="compare">
      <div class="lp-wrap center">
        <span class="eyebrow reveal">Excel &amp; email  vs  PanelOS</span>
        <h2 class="reveal">The same work, a fraction of the effort</h2>
        <div class="cmp reveal">
          <div class="cmp-head">
            <div class="ch task">Daily job</div>
            <div class="ch old">Excel + Email + WhatsApp</div>
            <div class="ch new">PanelOS</div>
          </div>
          <div v-for="(r,i) in compare" :key="i" class="cmp-row" :style="{ '--d': i * 45 + 'ms' }">
            <div class="cc task">{{ r.task }}</div>
            <div class="cc old"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M18 6L6 18M6 6l12 12"/></svg>{{ r.old }}</div>
            <div class="cc new"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><path d="M20 6L9 17l-5-5"/></svg>{{ r.new }}</div>
          </div>
        </div>
      </div>
    </section>

    <!-- ══ STATS (count up) ══ -->
    <section class="stats" ref="statsEl">
      <div class="lp-wrap stats-in">
        <div v-for="(s,i) in stats" :key="i" class="stat">
          <div class="n">{{ s.pre }}<span>{{ s.shown }}</span>{{ s.suf }}</div>
          <div class="l">{{ s.l }}</div>
        </div>
      </div>
    </section>

    <!-- ══ INTERACTIVE FEATURE EXPLORER ══ -->
    <section class="sec" id="features">
      <div class="lp-wrap center">
        <span class="eyebrow reveal">Explore the platform</span>
        <h2 class="reveal">One connected system, end to end</h2>
        <p class="lead reveal">Click any module to see it. A quote flows into an order, into production, into a GST invoice, into payment — nothing re-typed.</p>
      </div>
      <div class="lp-wrap explorer reveal">
        <div class="ex-tabs" role="tablist">
          <button v-for="(f,i) in featureTabs" :key="f.key"
                  :class="['ex-tab', { on: activeTab === i }]"
                  role="tab" :aria-selected="activeTab === i"
                  @click="activeTab = i">
            <span class="ti" v-html="f.icon"></span>
            <span>{{ f.title }}</span>
          </button>
        </div>
        <div class="ex-panel">
          <transition name="fade" mode="out-in">
            <div class="ex-body" :key="activeTab">
              <div class="ex-text">
                <h3>{{ featureTabs[activeTab].head }}</h3>
                <p>{{ featureTabs[activeTab].desc }}</p>
                <ul>
                  <li v-for="(b,bi) in featureTabs[activeTab].bullets" :key="bi">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><path d="M20 6L9 17l-5-5"/></svg>{{ b }}
                  </li>
                </ul>
              </div>
              <div class="ex-shot">
                <div class="frame">
                  <div class="fbar"><i></i><i></i><i></i><span class="url">{{ featureTabs[activeTab].title }}</span></div>
                  <img :src="'/guide/' + featureTabs[activeTab].img + '.png'" :alt="featureTabs[activeTab].title" loading="lazy">
                </div>
              </div>
            </div>
          </transition>
        </div>
      </div>
    </section>

    <!-- ══ HOW IT WORKS ══ -->
    <section class="sec alt" id="how">
      <div class="lp-wrap center">
        <span class="eyebrow reveal">How it works</span>
        <h2 class="reveal">From enquiry to money in the bank</h2>
        <p class="lead reveal">One flow. Each step hands clean data to the next.</p>
        <div class="flow">
          <div v-for="(st,i) in flow" :key="i" class="fstep reveal" :style="{ '--d': i * 90 + 'ms' }">
            <div class="fdot">{{ i + 1 }}</div>
            <h4>{{ st }}</h4>
            <svg v-if="i < flow.length - 1" class="farr" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
          </div>
        </div>
      </div>
    </section>

    <!-- ══ INDUSTRY FIT ══ -->
    <section class="sec dark">
      <div class="hero-bg"><span class="blob b3"></span></div>
      <div class="lp-wrap center">
        <span class="eyebrow light reveal">Built for panel makers</span>
        <h2 class="reveal" style="color:#fff">Not a generic ERP. Made for insulated panels.</h2>
        <p class="lead reveal" style="color:#C6D0EC">PUF, PIR, Rockwool, EPS &amp; Glasswool cores, skin materials, densities, thicknesses, RAL colours, sqm pricing and Indian GST — out of the box.</p>
        <div class="ind-chips reveal">
          <span v-for="(c,i) in industry" :key="i" class="ichip">{{ c }}</span>
        </div>
      </div>
    </section>

    <!-- ══ PRICING ══ -->
    <section class="sec" id="pricing">
      <div class="lp-wrap center">
        <span class="eyebrow reveal">Simple pricing</span>
        <h2 class="reveal">Plans that grow with your factory</h2>
        <p class="lead reveal">Every plan starts with a 14-day free trial. No card, cancel anytime.</p>
        <div class="price-grid">
          <div v-for="(pl,i) in plans" :key="i" :class="['plan reveal', { pop: pl.pop }]" :style="{ '--d': i * 80 + 'ms' }">
            <div v-if="pl.pop" class="ptag">Most popular</div>
            <div class="pname">{{ pl.name }}</div>
            <div class="pprice">₹{{ pl.price }}<span>/mo</span></div>
            <div class="pdesc">{{ pl.desc }}</div>
            <ul>
              <li v-for="(ft,fi) in pl.feats" :key="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><path d="M20 6L9 17l-5-5"/></svg>{{ ft }}</li>
            </ul>
            <button :class="['btn', pl.pop ? 'btn-primary' : 'btn-ghost']" @click="$emit('start-trial')">Start free trial</button>
          </div>
        </div>
      </div>
    </section>

    <!-- ══ FAQ ══ -->
    <section class="sec alt" id="faq">
      <div class="lp-wrap center">
        <span class="eyebrow reveal">Questions?</span>
        <h2 class="reveal">Frequently asked</h2>
        <div class="faq reveal">
          <div v-for="(q,i) in faqs" :key="i" :class="['qa', { open: openFaq === i }]">
            <button class="qa-q" @click="openFaq = openFaq === i ? -1 : i" :aria-expanded="openFaq === i">
              {{ q.q }}
              <svg class="pl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14"/></svg>
            </button>
            <div class="qa-a" :style="{ maxHeight: openFaq === i ? '220px' : '0' }"><p>{{ q.a }}</p></div>
          </div>
        </div>
      </div>
    </section>

    <!-- ══ FINAL CTA ══ -->
    <section class="sec">
      <div class="lp-wrap">
        <div class="final reveal">
          <div class="hero-bg"><span class="blob b4"></span></div>
          <h2 style="color:#fff;position:relative">Stop fighting spreadsheets. Start running your factory.</h2>
          <p style="position:relative">Join panel manufacturers moving from Excel &amp; WhatsApp to one clean system. See the difference today.</p>
          <div class="hero-cta" style="justify-content:center;position:relative">
            <button class="btn btn-white lg" @click="$emit('start-trial')">Start your 14-day free trial
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
            <a class="btn btn-glass lg" href="mailto:digitalwebweaver@gmail.com">Book a demo</a>
          </div>
        </div>
      </div>
    </section>

    <!-- ══ FOOTER ══ -->
    <footer class="foot">
      <div class="lp-wrap foot-top">
        <div>
          <a class="brand" href="#top" @click.prevent="scrollTo('top')"><span class="mark">P</span><span class="bn" style="color:#fff">PanelOS<small>by Digital Web Weaver</small></span></a>
          <p class="fdesc">The all-in-one ERP for PUF &amp; PIR insulated panel manufacturers — from enquiry to dispatch to payment.</p>
        </div>
        <div><h5>Product</h5><a href="#features" @click.prevent="scrollTo('features')">Features</a><a href="#how" @click.prevent="scrollTo('how')">How it works</a><a href="#pricing" @click.prevent="scrollTo('pricing')">Pricing</a><button class="link-btn fl" @click="$emit('sign-in')">Sign in</button></div>
        <div><h5>Company</h5><a href="https://digitalwebweaver.com" target="_blank" rel="noopener">Digital Web Weaver</a><a href="mailto:digitalwebweaver@gmail.com">Contact</a><a href="#faq" @click.prevent="scrollTo('faq')">FAQ</a></div>
        <div><h5>Get started</h5><button class="link-btn fl" @click="$emit('start-trial')">Start free trial</button><a href="mailto:digitalwebweaver@gmail.com">Book a demo</a></div>
      </div>
      <div class="lp-wrap foot-bot">
        <span>© {{ year }} PanelOS — a product by <b>Digital Web Weaver</b>.</span>
        <span>Made in India for panel manufacturers</span>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'

defineEmits(['sign-in', 'start-trial'])

const scrolled = ref(false)
const menuOpen = ref(false)
const activeTab = ref(0)
const openFaq = ref(0)
const year = new Date().getFullYear()
const statsEl = ref(null)

const marquee = ['Leads & CRM', 'BOQ & Quotations', 'Orders', 'Production Planning', 'Inventory & BOM', 'Dispatch', 'GST Invoicing', 'e-Invoice & e-Way', 'Payments', 'Receivables', 'Reports & MIS', 'Tally Export', 'WhatsApp Alerts']

const problems = [
  { t: 'Quotes take hours', d: 'Rebuilding rates & GST in Excel for every enquiry — one wrong formula loses you money.' },
  { t: 'Versions lost in chats', d: '"Which quotation is final?" Files scattered across email and WhatsApp with no history.' },
  { t: 'No idea of real stock', d: 'Coil and chemical levels are a guess — until a run stops halfway for missing material.' },
  { t: 'Production is a black box', d: 'You call the shop floor to know what is running, done, and stuck.' },
  { t: 'Payments slip through', d: 'Overdue invoices forgotten, follow-ups missed, cash stuck with customers.' },
  { t: 'No real numbers', d: 'Month-end means hours in Excel to know what you sold, made and collected.' },
]

const compare = [
  { task: 'Make a quotation', old: 'Hours of manual rates, formula errors', new: 'Minutes — BOQ → quote, auto rates & GST' },
  { task: 'Track versions', old: 'Lost across email & WhatsApp threads', new: 'One live record with full revision history' },
  { task: 'Know your stock', old: 'Guesswork or a physical count', new: 'Real-time coil, chemical & consumable levels' },
  { task: 'Production status', old: 'Phone calls to the shop floor', new: 'Live runs, batches & QC on one screen' },
  { task: 'Material consumed', old: 'Untracked wastage, no costing', new: 'Auto BOM consumption + wastage capture' },
  { task: 'GST / e-Invoice', old: 'Manual, error-prone, separate software', new: 'Auto CGST/SGST/IGST, e-Invoice & e-Way ready' },
  { task: 'Payment follow-up', old: 'Forgotten, no aging view', new: 'Receivables aging + WhatsApp reminders' },
  { task: 'Reports / MIS', old: 'Hours of copy-paste every month', new: 'Instant dashboards + one-click Tally export' },
]

const stats = ref([
  { pre: '', target: 10, suf: ' min', shown: 0, l: 'to send a priced quotation' },
  { pre: '', target: 100, suf: '%', shown: 0, l: 'GST-compliant invoices' },
  { pre: '', target: 9, suf: '', shown: 0, l: 'modules in one login' },
  { pre: '', target: 14, suf: '-day', shown: 0, l: 'free trial, no card' },
])

const ic = {
  leads: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>',
  quote: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>',
  prod: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-6 9 6v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>',
  stock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>',
  inv: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/></svg>',
  money: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>',
  rep: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/></svg>',
}
const featureTabs = [
  { key: 'leads', title: 'Leads', icon: ic.leads, img: 'leads', head: 'Never lose an enquiry again', desc: 'Capture every enquiry, assign an owner, set follow-ups, and convert hot leads straight into a priced quotation.', bullets: ['Source & owner tracking', 'Follow-up reminders with red badges', 'One-click convert to quotation'] },
  { key: 'quote', title: 'Quotations', icon: ic.quote, img: 'quotations', head: 'Price a job in minutes', desc: 'Start from a BOQ, apply panel-type rates and GST automatically, send a clean PDF, and keep every revision.', bullets: ['PUF/PIR specs, RAL colours, sqm pricing', 'Automatic GST, discounts & transport', 'Full revision history'] },
  { key: 'prod', title: 'Production', icon: ic.prod, img: 'production', head: 'See your whole shop floor', desc: 'Plan runs, track batches and QC, and consume material automatically as panels are made.', bullets: ['Run & batch planning', 'Live stage & QC tracking', 'Changeover-aware grouping cuts waste'] },
  { key: 'stock', title: 'Inventory', icon: ic.stock, img: 'stock', head: 'Real-time stock, zero surprises', desc: 'Live coil, chemical & consumable levels with reorder alerts and automatic BOM consumption.', bullets: ['Reorder-level alerts', 'Auto consumption at production', 'Wastage capture & valuation'] },
  { key: 'inv', title: 'Invoicing', icon: ic.inv, img: 'invoices', head: 'Bill correctly, every time', desc: 'GST-perfect invoices straight from a dispatch, with e-Invoice (IRN+QR) and e-Way bill ready.', bullets: ['Auto CGST/SGST/IGST + amount in words', 'e-Invoice & e-Way support', 'Professional PDF'] },
  { key: 'money', title: 'Receivables', icon: ic.money, img: 'receivables', head: 'Get paid faster', desc: 'Track every rupee outstanding with aging buckets and automatic WhatsApp payment reminders.', bullets: ['Live outstanding & aging', 'WhatsApp reminders', 'Payment history per customer'] },
  { key: 'rep', title: 'Reports', icon: ic.rep, img: 'reports', head: 'Know your numbers instantly', desc: 'Real-time dashboards for sales, production, stock and money — plus Tally export for your accountant.', bullets: ['Live MIS dashboards', 'Sales / production / stock views', 'One-click Tally export'] },
]

const flow = ['Lead', 'Quotation', 'Order', 'Production', 'Dispatch', 'GST Invoice', 'Payment']
const industry = ['PUF / PIR / Rockwool / EPS', 'Density & thickness', 'PPGI / PPGL / SS / Aluminium skins', 'RAL colours', 'SQM pricing', 'Indian GST & e-Invoice']

const plans = [
  { name: 'Starter', price: '2,999', desc: 'For small units getting off spreadsheets.', feats: ['Up to 3 users', 'Leads, Quotations & Orders', 'GST invoicing & basic reports'], pop: false },
  { name: 'Growth', price: '5,999', desc: 'For factories running full production.', feats: ['Up to 10 users', 'Everything in Starter', 'Production + Inventory + BOM', 'Tally export + WhatsApp alerts'], pop: true },
  { name: 'Pro', price: '9,999', desc: 'For multi-team operations at scale.', feats: ['Unlimited users', 'Everything in Growth', 'e-Invoice / e-Way + advanced MIS', 'Priority support'], pop: false },
]

const faqs = [
  { q: 'Do I need to install anything?', a: 'No. PanelOS runs in your browser on any laptop, desktop or phone. Just log in — nothing to install or maintain.' },
  { q: 'Is my data safe?', a: 'Yes. Your data lives securely in the cloud with automated backups and role-based access. Each company\'s data is fully isolated.' },
  { q: 'Is it GST compliant?', a: 'Absolutely. Invoices auto-calculate CGST/SGST/IGST with HSN codes, amount-in-words, and are e-Invoice (IRN) and e-Way bill ready. Tally export included.' },
  { q: 'Can my whole team use it together?', a: 'Yes — sales, production, stores and accounts all work on one live system with role-based permissions, so nothing conflicts.' },
  { q: 'I\'m not very technical. Is it hard?', a: 'PanelOS follows the natural flow of your business, and there is a built-in step-by-step guide with screenshots inside the app.' },
  { q: 'What happens after the free trial?', a: '14 days free, no card. After that pick a plan that fits — your data stays exactly where it is. No lock-in.' },
]

// ── scroll-state nav ──
function onScroll() { scrolled.value = window.scrollY > 12 }
function scrollTo(id) {
  menuOpen.value = false
  const el = document.getElementById(id)
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

// ── count-up stats ──
function runCounters() {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  stats.value.forEach((s) => {
    if (reduce) { s.shown = s.target; return }
    const dur = 1200, t0 = performance.now()
    const tick = (t) => {
      const p = Math.min(1, (t - t0) / dur)
      s.shown = Math.round((1 - Math.pow(1 - p, 3)) * s.target)
      if (p < 1) requestAnimationFrame(tick)
    }
    requestAnimationFrame(tick)
  })
}

let io = null, statsIo = null
onMounted(() => {
  window.addEventListener('scroll', onScroll, { passive: true })
  onScroll()
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  // reveal on scroll
  if (!reduce && 'IntersectionObserver' in window) {
    io = new IntersectionObserver((es) => {
      es.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target) } })
    }, { threshold: 0.14 })
    document.querySelectorAll('.lp .reveal').forEach((el) => io.observe(el))
  } else {
    document.querySelectorAll('.lp .reveal').forEach((el) => el.classList.add('in'))
  }
  // counters when stats visible
  if ('IntersectionObserver' in window && statsEl.value) {
    statsIo = new IntersectionObserver((es) => {
      es.forEach((e) => { if (e.isIntersecting) { runCounters(); statsIo.disconnect() } })
    }, { threshold: 0.4 })
    statsIo.observe(statsEl.value)
  } else { runCounters() }
})
onBeforeUnmount(() => {
  window.removeEventListener('scroll', onScroll)
  io && io.disconnect(); statsIo && statsIo.disconnect()
})
</script>

<style scoped>
.lp{--c:#2B50E0;--c2:#5C7CFF;--c3:#1B2A6B;--navy:#0B1228;--navy2:#0E1738;--ink:#141824;--body:#3D4458;--mut:#6B7280;--line:#E5E8EF;--bg:#fff;--bg2:#F6F8FC;--ok:#16A34A;--bad:#E2574C;--tint:#EEF1FE;--bd:#D6DEFB;
  font-family:'Public Sans',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--body);background:var(--bg);line-height:1.6;overflow-x:hidden;}
.lp *{box-sizing:border-box;}
.lp-wrap{max-width:1180px;margin:0 auto;padding:0 22px;}
.lp h1,.lp h2,.lp h3,.lp h4{color:var(--ink);line-height:1.12;letter-spacing:-.02em;font-weight:800;margin:0;}
.lp h2{font-size:clamp(27px,3.6vw,42px);}
.lp p{margin:0;}
.center{text-align:center;}
.lead{font-size:18px;color:var(--body);max-width:64ch;margin:14px auto 0;}
.eyebrow{display:inline-block;font-size:12.5px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--c);background:var(--tint);border:1px solid var(--bd);padding:6px 13px;border-radius:999px;}
.eyebrow.light{background:rgba(92,124,255,.16);border-color:rgba(92,124,255,.32);color:#AFC0FF;}
.sec{padding:90px 0;position:relative;}
.sec.alt{background:var(--bg2);}
.sec.dark{background:linear-gradient(180deg,var(--navy),var(--navy2));overflow:hidden;}
.sec h2{margin-bottom:6px;}
button{font-family:inherit;cursor:pointer;}

/* buttons */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;font-weight:700;font-size:16px;padding:13px 24px;border-radius:999px;border:2px solid transparent;transition:transform .16s,box-shadow .16s,background .16s,border-color .16s;white-space:nowrap;line-height:1;}
.btn svg{width:18px;height:18px;}
.btn.sm{padding:10px 18px;font-size:14.5px;}
.btn.lg{padding:16px 30px;font-size:16.5px;}
.btn-primary{background:var(--c);color:#fff;box-shadow:0 8px 24px rgba(43,80,224,.36);}
.btn-primary:hover{background:#2140C0;transform:translateY(-2px);box-shadow:0 14px 30px rgba(43,80,224,.46);}
.btn-ghost{background:#fff;color:var(--ink);border-color:var(--line);}
.btn-ghost:hover{border-color:var(--c);color:var(--c);transform:translateY(-2px);}
.btn-white{background:#fff;color:var(--c);}
.btn-white:hover{transform:translateY(-2px);box-shadow:0 14px 30px rgba(0,0,0,.2);}
.btn-glass{background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.28);backdrop-filter:blur(6px);}
.btn-glass:hover{background:rgba(255,255,255,.18);transform:translateY(-2px);}
.link-btn{background:none;border:0;color:#C6D0EC;font-weight:700;font-size:15px;padding:6px;}
.link-btn:hover{color:#fff;}

/* nav */
.nav{position:sticky;top:0;z-index:60;transition:background .25s,box-shadow .25s,border-color .25s;background:transparent;border-bottom:1px solid transparent;}
.scrolled .nav{background:rgba(11,18,40,.92);backdrop-filter:blur(12px);border-bottom-color:rgba(255,255,255,.08);box-shadow:0 6px 24px rgba(0,0,0,.18);}
.nav-in{display:flex;align-items:center;justify-content:space-between;height:70px;}
.brand{display:flex;align-items:center;gap:11px;color:#fff;font-weight:800;font-size:19px;text-decoration:none;}
.brand .mark{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#5C7CFF,#2B50E0);display:grid;place-items:center;color:#fff;font-weight:900;font-size:19px;box-shadow:0 6px 18px rgba(43,80,224,.5);}
.brand .bn{display:flex;flex-direction:column;line-height:1.05;}
.brand small{font-size:10.5px;font-weight:600;color:#9FB0D8;}
.nav-links{display:flex;gap:28px;}
.nav-links a{color:#C6D0EC;font-weight:600;font-size:15px;text-decoration:none;transition:color .15s;}
.nav-links a:hover{color:#fff;}
.nav-cta{display:flex;align-items:center;gap:12px;}
.burger{display:none;background:none;border:0;color:#fff;padding:6px;}
.burger svg{width:26px;height:26px;}

/* hero */
.hero{position:relative;background:linear-gradient(180deg,#0B1228 0%,#0E1738 70%,#101a3e 100%);color:#fff;padding:40px 0 0;overflow:hidden;}
.hero-bg{position:absolute;inset:0;overflow:hidden;pointer-events:none;}
.blob{position:absolute;border-radius:50%;filter:blur(70px);opacity:.55;background:radial-gradient(circle,#3a55d8,transparent 70%);animation:blob 16s ease-in-out infinite;}
.b1{width:520px;height:520px;top:-160px;right:-120px;background:radial-gradient(circle,#3f5ee6,transparent 70%);}
.b2{width:420px;height:420px;bottom:-160px;left:-120px;background:radial-gradient(circle,#5C7CFF,transparent 70%);animation-delay:-6s;}
.b3{width:600px;height:600px;top:-200px;left:50%;transform:translateX(-50%);background:radial-gradient(circle,#2b50e0,transparent 70%);opacity:.4;}
.b4{width:500px;height:500px;top:-180px;right:-100px;background:radial-gradient(circle,#5C7CFF,transparent 70%);opacity:.35;}
.grid-fade{position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);background-size:46px 46px;mask-image:radial-gradient(circle at 70% 20%,#000,transparent 75%);}
.hero-in{position:relative;display:grid;grid-template-columns:1.05fr 1fr;gap:50px;align-items:center;padding-top:36px;padding-bottom:70px;}
.hero .pill{display:inline-block;font-size:13px;font-weight:700;color:#AFC0FF;background:rgba(92,124,255,.14);border:1px solid rgba(92,124,255,.3);padding:6px 14px;border-radius:999px;}
.hero h1{color:#fff;font-size:clamp(34px,4.7vw,56px);font-weight:900;margin-top:18px;}
.grad{background:linear-gradient(120deg,#8AA2FF,#5C7CFF 60%,#A9B9FF);-webkit-background-clip:text;background-clip:text;color:transparent;}
.hero .sub{color:#C6D0EC;font-size:19px;margin-top:20px;max-width:55ch;}
.hero-cta{display:flex;gap:14px;margin-top:30px;flex-wrap:wrap;}
.trust{display:flex;gap:20px;flex-wrap:wrap;margin-top:26px;color:#9FB0D8;font-size:14px;font-weight:600;}
.trust span{display:flex;align-items:center;gap:7px;}
.trust svg{width:16px;height:16px;color:#5CC98A;}
.hero-shot{position:relative;}
.frame{background:#0a1029;border:1px solid rgba(255,255,255,.12);border-radius:14px;box-shadow:0 30px 70px rgba(5,10,30,.55);overflow:hidden;}
.frame.tilt{transform:perspective(1400px) rotateY(-7deg) rotateX(3deg);transition:transform .5s cubic-bezier(.4,0,.2,1);}
.hero-shot:hover .frame.tilt{transform:perspective(1400px) rotateY(0) rotateX(0);}
.fbar{display:flex;align-items:center;gap:7px;padding:11px 14px;background:#11183a;border-bottom:1px solid rgba(255,255,255,.08);}
.fbar i{width:11px;height:11px;border-radius:50%;background:#3a4570;}
.fbar i:nth-child(1){background:#ff5f57;}.fbar i:nth-child(2){background:#febc2e;}.fbar i:nth-child(3){background:#28c840;}
.fbar .url{margin-left:8px;font-size:12px;color:#8290bf;background:#0a1029;padding:4px 12px;border-radius:6px;flex:1;}
.frame img{width:100%;display:block;}
.fbadge{position:absolute;background:#fff;color:var(--ink);border-radius:13px;box-shadow:0 14px 36px rgba(5,10,30,.3);padding:12px 15px;display:flex;align-items:center;gap:11px;font-weight:700;font-size:14px;}
.fbadge small{display:block;font-size:11px;color:var(--mut);font-weight:600;}
.fbadge .fi{width:34px;height:34px;border-radius:9px;display:grid;place-items:center;flex:none;}
.fbadge .fi svg{width:19px;height:19px;}
.fi.ok{background:#E9F7EF;color:var(--ok);}.fi.br{background:var(--tint);color:var(--c);}
.fb1{top:-20px;left:-26px;}.fb2{bottom:30px;right:-26px;}

/* marquee */
.marquee{position:relative;border-top:1px solid rgba(255,255,255,.08);background:rgba(0,0,0,.18);overflow:hidden;padding:14px 0;}
.mq-track{display:flex;gap:14px;width:max-content;animation:marquee 32s linear infinite;}
.mq-chip{flex:none;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:#C6D0EC;font-weight:700;font-size:13.5px;padding:8px 16px;border-radius:999px;}

/* problem */
.prob-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:42px;text-align:left;}
.prob{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px;display:flex;gap:14px;align-items:flex-start;transition:transform .18s,box-shadow .18s,border-color .18s;}
.prob:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(13,20,48,.1);border-color:var(--bd);}
.prob .x{flex:none;width:36px;height:36px;border-radius:10px;background:#FCEDEC;color:var(--bad);display:grid;place-items:center;}
.prob .x svg{width:18px;height:18px;}
.prob h4{font-size:16px;margin-bottom:3px;}
.prob p{font-size:14.5px;}

/* comparison */
.cmp{max-width:980px;margin:42px auto 0;border:1px solid var(--line);border-radius:18px;overflow:hidden;box-shadow:0 16px 44px rgba(13,20,48,.08);background:#fff;}
.cmp-head{display:grid;grid-template-columns:24% 38% 38%;background:var(--bg2);}
.ch{padding:15px 18px;font-weight:800;color:var(--ink);font-size:15px;text-align:left;}
.ch.old{color:var(--mut);}.ch.new{color:var(--c);background:var(--tint);}
.cmp-row{display:grid;grid-template-columns:24% 38% 38%;border-top:1px solid var(--line);}
.cc{padding:14px 18px;font-size:14.5px;display:flex;gap:9px;align-items:flex-start;text-align:left;}
.cc.task{font-weight:700;color:var(--ink);}
.cc.old{color:var(--mut);}
.cc.new{color:var(--ink);background:#FAFBFF;}
.cc svg{flex:none;width:18px;height:18px;margin-top:1px;}
.cc.old svg{color:var(--bad);}.cc.new svg{color:var(--ok);}

/* stats */
.stats{background:linear-gradient(135deg,var(--c) 0%,#1B2A6B 100%);color:#fff;padding:64px 0;}
.stats-in{display:grid;grid-template-columns:repeat(4,1fr);gap:26px;text-align:center;}
.stat .n{font-size:clamp(34px,4.4vw,50px);font-weight:900;letter-spacing:-.03em;line-height:1;}
.stat .l{margin-top:8px;color:#C6D0EC;font-size:15px;font-weight:600;}

/* explorer */
.explorer{display:grid;grid-template-columns:300px 1fr;gap:26px;margin-top:42px;align-items:start;}
.ex-tabs{display:flex;flex-direction:column;gap:8px;}
.ex-tab{display:flex;align-items:center;gap:12px;text-align:left;background:#fff;border:1px solid var(--line);border-radius:13px;padding:14px 16px;font-weight:700;color:var(--body);font-size:15px;transition:all .16s;}
.ex-tab:hover{border-color:var(--bd);color:var(--ink);}
.ex-tab.on{background:var(--c);color:#fff;border-color:var(--c);box-shadow:0 10px 24px rgba(43,80,224,.32);}
.ex-tab :deep(svg){width:21px;height:21px;flex:none;}
.ex-tab .ti{display:grid;place-items:center;}
.ex-panel{border:1px solid var(--line);border-radius:18px;background:#fff;overflow:hidden;box-shadow:0 16px 44px rgba(13,20,48,.08);min-height:380px;}
.ex-body{display:grid;grid-template-columns:1fr 1.15fr;gap:0;}
.ex-text{padding:30px 28px;}
.ex-text h3{font-size:22px;margin-bottom:10px;}
.ex-text p{font-size:15.5px;}
.ex-text ul{list-style:none;margin-top:18px;display:grid;gap:11px;padding:0;}
.ex-text li{display:flex;gap:10px;align-items:flex-start;font-size:15px;color:var(--body);}
.ex-text li svg{flex:none;width:19px;height:19px;color:var(--ok);margin-top:1px;}
.ex-shot{background:var(--bg2);border-left:1px solid var(--line);padding:22px;display:flex;align-items:center;}
.ex-shot .frame{box-shadow:0 14px 36px rgba(13,20,48,.16);border-color:var(--line);width:100%;}
.ex-shot .fbar{background:#fff;border-bottom:1px solid var(--line);}
.ex-shot .fbar .url{background:var(--bg2);color:var(--mut);}

/* flow */
.flow{display:flex;justify-content:center;flex-wrap:wrap;gap:6px 0;margin-top:46px;}
.fstep{flex:1;min-width:120px;max-width:160px;text-align:center;position:relative;padding:0 6px;}
.fdot{width:56px;height:56px;border-radius:16px;background:#fff;border:1px solid var(--bd);color:var(--c);display:grid;place-items:center;margin:0 auto 12px;font-weight:900;font-size:20px;box-shadow:0 8px 22px rgba(43,80,224,.14);}
.fstep h4{font-size:15px;}
.farr{position:absolute;top:18px;right:-12px;width:24px;height:24px;color:var(--bd);}

/* industry */
.ind-chips{display:flex;justify-content:center;flex-wrap:wrap;gap:12px;margin-top:30px;}
.ichip{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);color:#fff;font-weight:700;font-size:14px;padding:10px 18px;border-radius:999px;}

/* pricing */
.price-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px;margin-top:46px;align-items:start;text-align:left;}
.plan{background:#fff;border:1px solid var(--line);border-radius:20px;padding:30px 26px;position:relative;transition:transform .18s,box-shadow .18s;}
.plan:hover{transform:translateY(-5px);box-shadow:0 22px 50px rgba(13,20,48,.12);}
.plan.pop{border:2px solid var(--c);box-shadow:0 22px 50px rgba(43,80,224,.18);}
.ptag{position:absolute;top:-13px;left:50%;transform:translateX(-50%);background:var(--c);color:#fff;font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;padding:5px 14px;border-radius:999px;}
.pname{font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--c);}
.pprice{font-size:42px;font-weight:900;color:var(--ink);margin:10px 0 2px;letter-spacing:-.03em;}
.pprice span{font-size:16px;font-weight:600;color:var(--mut);}
.pdesc{font-size:14px;color:var(--mut);margin-bottom:18px;}
.plan ul{list-style:none;display:grid;gap:11px;margin:0 0 24px;padding:0;}
.plan li{display:flex;gap:10px;align-items:flex-start;font-size:14.5px;color:var(--body);}
.plan li svg{flex:none;width:18px;height:18px;color:var(--ok);margin-top:2px;}
.plan .btn{width:100%;}

/* faq */
.faq{max-width:800px;margin:38px auto 0;text-align:left;}
.qa{border:1px solid var(--line);border-radius:12px;margin-bottom:12px;background:#fff;overflow:hidden;transition:border-color .15s,box-shadow .15s;}
.qa.open{border-color:var(--bd);box-shadow:0 10px 26px rgba(13,20,48,.07);}
.qa-q{width:100%;text-align:left;background:none;border:0;padding:18px 22px;font-weight:700;color:var(--ink);font-size:16.5px;display:flex;justify-content:space-between;align-items:center;gap:14px;}
.qa-q .pl{flex:none;width:22px;height:22px;color:var(--c);transition:transform .25s;}
.qa.open .qa-q .pl{transform:rotate(135deg);}
.qa-a{max-height:0;overflow:hidden;transition:max-height .3s cubic-bezier(.4,0,.2,1);}
.qa-a p{padding:0 22px 20px;color:var(--body);font-size:15.5px;}

/* final */
.final{position:relative;background:linear-gradient(135deg,#0B1228,#1B2A6B);color:#fff;text-align:center;border-radius:26px;padding:64px 26px;overflow:hidden;box-shadow:0 30px 70px rgba(5,10,30,.3);}
.final h2{font-size:clamp(28px,3.6vw,42px);}
.final p{color:#C6D0EC;font-size:18px;margin:14px auto 28px;max-width:54ch;}

/* footer */
.foot{background:var(--navy);color:#9FB0D8;padding:56px 0 26px;}
.foot-top{display:grid;grid-template-columns:1.5fr 1fr 1fr 1fr;gap:30px;}
.foot h5{color:#fff;font-size:13px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;margin:0 0 14px;}
.foot a,.foot .fl{display:block;color:#9FB0D8;margin-bottom:9px;text-decoration:none;transition:color .15s;text-align:left;background:none;border:0;padding:0;font-size:14.5px;font-weight:400;font-family:inherit;}
.foot a:hover,.foot .fl:hover{color:#fff;}
.fdesc{color:#8290bf;font-size:14px;margin-top:12px;max-width:34ch;}
.foot-bot{border-top:1px solid rgba(255,255,255,.1);margin-top:36px;padding-top:22px;display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;color:#7282ad;font-size:13.5px;}
.foot-bot b{color:#fff;}

/* ── animations ── */
@keyframes blob{0%,100%{transform:translate(0,0) scale(1);}33%{transform:translate(30px,-20px) scale(1.08);}66%{transform:translate(-20px,20px) scale(.95);}}
@keyframes marquee{from{transform:translateX(0);}to{transform:translateX(-50%);}}
@keyframes floaty{0%,100%{transform:translateY(0);}50%{transform:translateY(-12px);}}
@keyframes fadeUp{from{opacity:0;transform:translateY(26px);}to{opacity:1;transform:none;}}
@keyframes fadeIn{from{opacity:0;transform:scale(.96);}to{opacity:1;transform:none;}}
.floaty{animation:floaty 5s ease-in-out infinite;}
.floaty.d1{animation-delay:-2.5s;}
.anim-up{opacity:0;animation:fadeUp .7s cubic-bezier(.4,0,.2,1) forwards;}
.anim-in{opacity:0;animation:fadeIn .8s cubic-bezier(.4,0,.2,1) forwards;}
.d1{animation-delay:.1s;}.d2{animation-delay:.2s;}.d3{animation-delay:.3s;}.d4{animation-delay:.4s;}
.reveal{opacity:0;transform:translateY(26px);transition:opacity .6s cubic-bezier(.4,0,.2,1) var(--d,0ms),transform .6s cubic-bezier(.4,0,.2,1) var(--d,0ms);}
.reveal.in{opacity:1;transform:none;}
.fade-enter-active,.fade-leave-active{transition:opacity .25s,transform .25s;}
.fade-enter-from{opacity:0;transform:translateY(10px);}
.fade-leave-to{opacity:0;transform:translateY(-10px);}

/* responsive */
@media (max-width:920px){
  .hero-in{grid-template-columns:1fr;gap:40px;} .hero-shot{max-width:560px;margin:0 auto;}
  .prob-grid,.price-grid{grid-template-columns:1fr 1fr;}
  .explorer{grid-template-columns:1fr;} .ex-tabs{flex-direction:row;flex-wrap:wrap;} .ex-tab{flex:1;min-width:130px;}
  .ex-body{grid-template-columns:1fr;} .ex-shot{border-left:0;border-top:1px solid var(--line);}
  .stats-in{grid-template-columns:1fr 1fr;gap:34px 20px;}
  .foot-top{grid-template-columns:1fr 1fr;}
  .fbadge{display:none;} .frame.tilt{transform:none;}
}
@media (max-width:600px){
  .sec{padding:62px 0;}
  .nav-links{display:none;} .nav-links.open{display:flex;position:absolute;top:70px;left:0;right:0;flex-direction:column;background:var(--navy2);padding:18px 22px;gap:16px;border-bottom:1px solid rgba(255,255,255,.1);}
  .burger{display:block;} .nav-cta .link-btn{display:none;}
  .prob-grid,.price-grid{grid-template-columns:1fr;}
  .cmp-head{display:none;}
  .cmp-row{grid-template-columns:1fr;gap:0;padding:6px 0;}
  .cc{padding:8px 16px;} .cc.task{padding-top:12px;font-size:15px;}
  .flow{gap:18px;} .farr{display:none;} .fstep{min-width:130px;}
  .foot-top{grid-template-columns:1fr;}
}
@media (prefers-reduced-motion:reduce){
  .blob,.floaty,.mq-track{animation:none!important;}
  .anim-up,.anim-in{opacity:1;animation:none;}
  .reveal{opacity:1;transform:none;transition:none;}
  .frame.tilt{transform:none;}
}
</style>
