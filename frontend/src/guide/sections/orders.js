export default {
  key: 'orders',
  title: 'Orders',
  group: 'Sales',
  img: 'orders',

  summary: 'An order is the confirmed job made from an accepted quotation. Track its delivery date, production batches and status until everything is dispatched.',

  fits: 'Made from an accepted quotation. Pending orders are picked up in **Production Plan** and **Production Runs**; batches then go through QC, dispatch and invoicing.',

  prereqs: [
    'An Accepted quotation (see Quotations).',
    'The customer has room in their credit limit, or an admin overrides it.',
  ],

  tasks: [
    {
      title: 'Create an order from a quotation',
      steps: [
        'Open **Quotations**, open the Accepted quotation and click **Create Order**.',
        'The order is created as Pending, dated today, with delivery set 14 days ahead.',
        'Panel rows, sizes, rates, GST, transport and advance are copied from the quotation. Later quotation changes do not affect it.',
        'If the quotation came from a lead, the lead is marked won. If customer messaging is set up, the customer gets an order-confirmed message.',
      ],
    },
    {
      title: 'Find and open an order',
      steps: [
        'Open **Orders** (the page is headed **Production Orders**).',
        'Search by order number, project or customer, or filter by status. Click a column heading to sort.',
        'Click a row to open it. An **Overdue** tag shows when the delivery date has passed.',
      ],
    },
    {
      title: 'Change the delivery date or notes',
      steps: [
        'Open the order.',
        'Change **Expected Delivery Date** and/or **Notes**.',
        'Click **Save**. Nothing else on an order can be edited.',
      ],
    },
    {
      title: 'Send the order to production',
      steps: [
        'Go to **Production Plan** or **Production Runs** and create a run that includes the order.',
        'When its batch is created, the order moves to In Production by itself.',
        'Batches appear under **Production Batches** on the order. Click one to open it.',
      ],
    },
    {
      title: 'Complete an order',
      steps: [
        'Wait until every batch of the order shows dispatched.',
        'Open the order and click **Mark Completed**. The button stays disabled until then.',
      ],
    },
    {
      title: 'Cancel an order',
      steps: [
        'Open a Pending or In Production order and click **Cancel**.',
        'Confirm with **Cancel Order**. This cannot be undone.',
      ],
    },
    {
      title: 'See the quotation behind an order',
      steps: [
        'Open the order and click **View Quotation**. The **Source** box also shows the quotation number.',
      ],
    },
  ],

  fields: [
    { name: 'Order No', rule: 'Set automatically, e.g. ORD-2027-001.', note: 'Prefix from company settings; numbering restarts each financial year (April).' },
    { name: 'Order date', rule: 'Set automatically to the day it is created.', note: '' },
    { name: 'Expected Delivery Date', rule: 'Optional date. Default 14 days after the order.', note: 'Past dates show as Overdue.' },
    { name: 'Notes', rule: 'Optional, up to 1000 characters.', note: 'Starts with the quotation notes.' },
    { name: 'Advance / Balance Due', rule: 'Copied from the quotation.', note: 'Information only; production is not held for the advance.' },
    { name: 'GST', rule: 'Copied from the quotation.', note: 'IGST for another state, otherwise CGST + SGST.' },
  ],

  statuses: [
    { name: 'Pending', meaning: 'Created, no batch yet. Shown in Production Plan for a run.', next: 'In Production, Cancelled' },
    { name: 'In Production', meaning: 'At least one batch exists. Set automatically when a batch is created.', next: 'Completed, Cancelled' },
    { name: 'Completed', meaning: 'Every batch has been dispatched and a user marked it completed.', next: 'None' },
    { name: 'Cancelled', meaning: 'Job called off. Not counted in the customer\'s credit exposure.', next: 'None' },
  ],

  cases: [
    { title: 'Credit limit blocks the order', body: 'The message shows exposure, this order and the limit. Collect payment or raise the limit on the customer; an admin can choose **Override & create**.' },
    { title: 'Order with an advance', body: 'Advance % and amounts are printed and stored, but nothing waits for the advance. Record money against the invoice on the **Invoices** page.' },
    { title: 'Customer wants a later delivery', body: 'Open the order, change **Expected Delivery Date** and click **Save**.' },
    { title: 'Customer changes sizes after ordering', body: 'An order cannot be edited, and its quotation can no longer be revised. Cancel it if needed and make a new quotation (Duplicate) for the changed job.' },
    { title: 'Cancel an order already in production', body: 'Cancel only changes the order status. Runs, batches, dispatches and invoices already made are not stopped or removed; handle them on their own pages.' },
    { title: 'Run cancelled', body: 'Cancelling a draft run removes its batches and puts the order back to Pending, ready for another run.' },
    { title: 'Accessories and installation not on the order page', body: 'The order page lists panels only. Accessories, doors and installation from the quotation are still billed on the invoice.' },
    { title: 'Mark Completed is greyed out', body: 'At least one batch is not yet dispatched, or the order has no batches. Finish dispatch first.' },
  ],

  errors: [
    { message: 'An order already exists for this quotation.', fix: 'One quotation gives one order. Find it in **Orders**.' },
    { message: 'Quotation must be accepted to create an order.', fix: 'Accept the quotation first.' },
    { message: 'Credit limit exceeded for (customer). Exposure ₹X + this order ₹Y would exceed the ₹Z limit by ₹W.', fix: 'Collect payment, raise the customer\'s limit, or ask an admin to override.' },
    { message: 'An order can only be marked completed once all its production batches are dispatched.', fix: 'Complete dispatch of every batch, then try again.' },
    { message: 'Order not found', fix: 'It was deleted or belongs to another company. Go back to the list.' },
  ],

  tips: [
    'Do not use **Start Production** on a Pending order. It only changes the status, and the order then drops out of Production Plan and new runs.',
    'Creating a run or batch sets In Production for you; there is no need to change it by hand.',
    'An order is a snapshot of the quotation. Changing the quotation later does not change the order.',
    'Keep **Expected Delivery Date** current; the list flags overdue orders.',
  ],

  access: 'Viewing needs "View orders"; changing date, notes or status needs "Manage orders". Creating an order is done from the quotation and needs "Create / edit quotations, enter rates". Only admins can override a credit limit.',
}
