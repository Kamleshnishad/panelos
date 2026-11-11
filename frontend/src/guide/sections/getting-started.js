export default {
  key: 'getting-started',
  title: 'Getting started',
  group: 'Getting started',
  img: null,
  summary: 'PanelOS runs your factory from the first enquiry to the last payment: one record moves from lead to quotation, order, production, dispatch, invoice and payment.',
  fits: 'Lead → Customer → BOQ (optional) → Quotation → Order → Production run and batches → Quality Control → Dispatch → Invoice → Payment. Stock, Procurement and Reports run alongside.',
  prereqs: [
    'A login from your company admin (Settings → **Users & Roles**).',
    'Before the first quotation: your GSTIN and state, panel types with base prices, and at least one customer.',
    'Before the first production run: production stages, coil stock for every panel type, and Polyol and Isocyanate stock.',
  ],
  tasks: [
    {
      title: 'Find your way around',
      steps: [
        'The left sidebar lists every page, grouped as **Main**, **Inventory**, **Sales & Finance** and **Settings**. A red number on a page means items need attention.',
        'The search box at the top finds quotations, orders, invoices, customers, batches and dispatches by number or name. Press **/** to jump to it.',
        'The bell shows alerts such as low stock or overdue payments.',
        'Your name at the top right opens **My Profile** (change password) and **Log out**.',
        'The **?** button next to it opens this guide at the page you are on.',
      ],
    },
    {
      title: 'Set up a new company (first day)',
      steps: [
        'Open **Dashboard**. The **Finish setting up** card lists what is still missing; each line links to the right page.',
        'Settings → **Company**: legal name, address, GSTIN and state, bank details, logo.',
        'Settings → **Master Data** → **Panel Types**: create each panel you sell with its thicknesses and a base price.',
        'Settings → **Master Data** → **Production Stages**: the steps your floor follows.',
        '**Stock**: add coil stock for every panel type, and Polyol and Isocyanate chemical stock.',
        '**Customers**: add your first customer with GSTIN and state.',
        'Settings → **Users & Roles**: give each person a login with only the access they need.',
      ],
    },
    {
      title: 'Follow one order from start to finish',
      steps: [
        '**Leads**: record the enquiry, then convert it to a customer.',
        '**BOQ Register** (optional): list the panels and sizes without rates, then **Convert to Quotation**.',
        '**Quotations**: enter rates, send the PDF, mark it accepted and create the order.',
        '**Production Plan** and **Production Runs**: group orders with the same panel spec and start the run; raw material is issued from stock.',
        '**Production**: the floor records cuts and rejected panels per length; complete the batch.',
        '**Quality Control**: inspect, pass and approve the batch.',
        '**Dispatches**: create the dispatch with vehicle and challan, then mark it delivered.',
        '**Invoices**: raise the tax invoice, then record payments under **Receivables**.',
      ],
    },
  ],
  fields: [],
  statuses: [],
  cases: [
    { title: 'A page says something is missing', body: 'Most "cannot continue" messages mean master data is missing. Check the **Finish setting up** card on the Dashboard first.' },
    { title: 'Someone cannot see a page or button', body: 'Their role lacks that permission. A company admin can change it under Settings → **Users & Roles**.' },
    { title: 'You were signed out', body: 'Sessions end after a period of inactivity. A warning appears first; click to stay signed in.' },
  ],
  errors: [
    { message: 'You do not have permission to perform this action.', fix: 'Ask your company admin to add the permission to your role.' },
  ],
  tips: [
    'Every record has its own page address, so you can bookmark it or paste the link to a colleague.',
    'Amounts are shown in Indian format (₹1,23,456.00).',
    'Work in the order of the flow: a step only unlocks when the one before it is complete.',
  ],
  access: 'Everyone with a login. Each page below says which permission it needs.',
}
