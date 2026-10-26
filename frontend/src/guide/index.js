// In-app Help & Guide content. One file per page under ./sections/, each exporting a default object
// of the shape below. GuidePage.vue renders them in GUIDE_ORDER; the header's "?" button deep-links to
// /guide#<key> for the page you are on. Write for factory office staff: plain words, the exact on-screen
// labels, and only behaviour the system really has (SYSTEM.md is the source of truth).
//
// {
//   key:        'quotations',                 // = the app's page key (AppShell nav), also the #anchor
//   title:      'Quotations',                 // as in the sidebar
//   group:      'Sales',                      // Getting started · Sales · Production · Inventory · Dispatch & Finance · Settings · Help
//   img:        'quotations',                 // /guide/<img>.png screenshot, or null
//   summary:    'One or two sentences: what this page is for.',
//   fits:       'Where it sits in the flow: what comes before and after.',
//   prereqs:    ['What must exist first …'],
//   tasks:      [{ title: 'Create a quotation', steps: ['Click **New Quotation** …', '…'] }],  // the main how-tos
//   fields:     [{ name: 'Validity days', rule: 'Required, 1–365', note: 'Sets the Valid until date.' }],
//   statuses:   [{ name: 'Draft', meaning: 'Being prepared; editable.', next: 'Sent, Rejected' }],     // optional
//   cases:      [{ title: 'Customer asks for a lower rate after sending', body: 'Use **Revise** …' }],
//   errors:     [{ message: 'Exact message the user sees', fix: 'What to do' }],
//   tips:       ['Short practical tips'],
//   access:     'Who can see / change it (permission in plain words).',
// }
//
// Text may use **bold** for on-screen labels; nothing else is interpreted (no HTML).

const modules = import.meta.glob('./sections/*.js', { eager: true })

export const GUIDE_ORDER = [
  'getting-started',
  'dashboard', 'leads', 'customers', 'boq', 'quotations', 'orders',
  'planner', 'runs', 'batches', 'qc', 'stock', 'procurement',
  'dispatches', 'invoices', 'receivables', 'reports',
  'company', 'notifications', 'billing', 'doctemplates', 'master', 'users', 'audit', 'profile',
  'faq',
]

const byKey = Object.fromEntries(Object.values(modules).map(m => [m.default.key, m.default]))

/** Sections that exist, in reading order (missing ones are simply not written yet). */
export const guideSections = GUIDE_ORDER.map(k => byKey[k]).filter(Boolean)
