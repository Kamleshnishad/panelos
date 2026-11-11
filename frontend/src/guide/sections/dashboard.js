export default {
  key: 'dashboard',
  title: 'Dashboard',
  group: 'Getting started',
  img: 'dashboard',
  summary: 'Your business at a glance: what needs action today, this financial year\'s sales and collections, and charts for sales, leads, cash and production.',
  fits: 'The home page. It only reads data from the other pages; nothing is entered here.',
  prereqs: ['Numbers appear once quotations, orders, invoices and payments exist.'],
  tasks: [
    {
      title: 'Read the dashboard from top to bottom',
      steps: [
        '**Finish setting up** (only until setup is complete): the missing setup steps, each linking to its page.',
        'The first row of cards shows work in progress: **Open Quotations**, **Active Orders**, **In Production**, **Pending Dispatch**, **Outstanding** and **Collected (FY)**. Click a card to open its page.',
        'The second row shows this financial year (from 1 April): **Invoiced (FY)**, **Orders (FY)**, **Avg Order Value**, **Quote Win Rate**, **Collection Efficiency**, **DSO** and **On-time Delivery**.',
        '**Sales & Revenue** charts: revenue vs collections over 12 months, quotations vs orders, top customers, revenue by state and quotation outcomes.',
        '**Leads & Cash** charts: lead funnel, lead sources, collections by payment method and receivables aging.',
        '**Operations** charts: production output per month, batch status and dispatch status.',
        'Below the charts: **Alerts**, the **Order-to-Cash Pipeline** and **Recent Activity**.',
      ],
    },
    {
      title: 'See the exact numbers behind a chart',
      steps: [
        'Hover over a bar, line or slice to see its value.',
        'Click **Table** at the top right of a chart to see every value as a table; click **Chart** to switch back.',
      ],
    },
    {
      title: 'Refresh the numbers',
      steps: ['Click **Refresh** at the top. Figures are kept for up to one minute, so a change you just made can take a minute to appear.'],
    },
  ],
  fields: [
    { name: 'Quote Win Rate', rule: 'Accepted ÷ (accepted + rejected) quotations this FY', note: 'Quotations still open are not counted.' },
    { name: 'Collection Efficiency', rule: 'Money collected ÷ invoiced this FY', note: 'Above 100% means old dues were collected too.' },
    { name: 'DSO (days sales outstanding)', rule: 'Outstanding ÷ average daily billing of the last 90 days', note: 'Lower is better; shows — until there is billing.' },
    { name: 'On-time Delivery', rule: 'Delivered dispatches on or before the expected date ÷ all delivered', note: 'Dispatches without an expected date count as on time.' },
    { name: 'Revenue by State / Payment methods', rule: 'Top 6 / top 4, the rest grouped as **Other**', note: 'Keeps charts readable.' },
  ],
  statuses: [],
  cases: [
    { title: 'A number looks wrong', body: 'Click **Table** on the chart to see each value, then open the source page (for example Invoices) for that month to check the records.' },
    { title: 'Test or demo records are inflating figures', body: 'Cancel or remove them; cancelled orders and invoices are excluded from every figure.' },
    { title: 'A chart says "No data yet"', body: 'Nothing has been recorded for that measure in the period yet. It fills in as you use the matching page.' },
  ],
  errors: [
    { message: 'Failed to load dashboard.', fix: 'Click **Refresh**. If it keeps failing, check your internet connection and sign in again.' },
  ],
  tips: [
    'Use the dashboard every morning: red numbers on cards (overdue orders, overdue invoices) are today\'s priorities.',
    'Collected (FY) excludes write-offs; Outstanding excludes draft and cancelled invoices.',
  ],
  access: 'Everyone who can sign in sees the dashboard. Clicking a card opens a page only if your role can see it.',
}
