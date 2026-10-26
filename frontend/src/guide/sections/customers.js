export default {
  key: 'customers',
  title: 'Customers',
  group: 'Sales',
  img: 'customers',

  summary: 'The list of everyone you quote, sell and invoice to. Each customer holds contact details, GSTIN and GST state, customer type, credit limit and payment terms, plus a 360° profile of their business with you.',

  fits: 'A customer comes from a converted lead or is added here directly. Every BOQ and quotation needs one; orders, dispatches and invoices then follow from that quotation.',

  prereqs: [
    'Your company profile (Settings → Company) should have its GSTIN and state, so the system can tell intra-state from inter-state GST.',
    'You need permission to manage customers to add or edit them.',
  ],

  tasks: [
    {
      title: 'Add a customer',
      steps: [
        'Open **Customers** and click **+ New Customer**.',
        'Fill **Customer Name** and **Phone** (both required).',
        'Pick **Customer Type** and **State (GST)**; add GSTIN, address and other details if you have them.',
        'Set **Credit Limit (₹)** and **Payment Terms (days)** if this customer buys on credit.',
        'Click **Add Customer**. A customer code is created for you and shown next to the name in the list.',
      ],
    },
    {
      title: 'Add a customer while making a BOQ or quotation',
      steps: [
        'On the BOQ or quotation form, click **+ New** next to **Customer**.',
        'Fill the same new-customer form and click **Add Customer**.',
        'Check that the **Customer** box now shows the customer you just added before you continue.',
      ],
    },
    {
      title: 'Edit a customer',
      steps: [
        'In the list, click **Edit** on the customer row, or open the profile and click **Edit Details**.',
        'Change what you need and click **Save Changes**.',
        'Clearing **Credit Limit (₹)** sets it back to 0 (no limit); clearing **Payment Terms (days)** sets it back to 30.',
      ],
    },
    {
      title: 'Set the GSTIN and GST state',
      steps: [
        'Type the 15-character **GSTIN** (optional). Each GSTIN can belong to only one customer.',
        'Choose the state in **State (GST)**. This decides CGST + SGST (same state as you) or IGST (different state).',
        'If **State (GST)** is left blank, the state is taken from the first two digits of the GSTIN.',
      ],
    },
    {
      title: 'Set a credit limit and payment terms',
      steps: [
        'Enter **Credit Limit (₹)**. 0 means no limit.',
        'Enter **Payment Terms (days)**, 0 to 365. It is kept for reference; invoices still default to a 30-day due date.',
        'When an order is created, the system adds unpaid invoices, uninvoiced orders and the new order. If that passes the limit, the order is blocked.',
      ],
    },
    {
      title: 'Choose the customer type',
      steps: [
        'Pick **retail**, **wholesale**, **distributor** or **corporate** in **Customer Type** (default retail).',
        'The type only changes the suggested rate on quotations: wholesale 5% lower, distributor 10% lower, retail and corporate unchanged.',
        'Rates you type yourself are never changed by the type.',
      ],
    },
    {
      title: 'Find a customer',
      steps: [
        'Type in **Search name / phone / email…** and press Enter.',
        'Click ↻ to reload the list. Use **← Prev** and **Next →** when there are many pages.',
      ],
    },
    {
      title: 'View the 360° profile and history',
      steps: [
        'Click a customer row or **View 360 →**.',
        'The top shows **Total Business**, **Orders**, **Avg Order Value**, **Outstanding** and **Repeat Frequency**, plus a segment badge and a 12-month order chart.',
        'Use the tabs **Overview**, **Quotations**, **Orders**, **Invoices**, **Dispatches** and **Leads** to see each record.',
        'Click a quotation row to open that quotation. Click **← All Customers** to go back.',
      ],
    },
    {
      title: 'Get a customer statement',
      steps: [
        'Go to **Reports** and open the **Statements** tab.',
        'Search and pick the customer under **Customer**; the statement and still-unpaid invoices appear.',
        'Click **Statement (PDF)** to download it.',
      ],
    },
  ],

  fields: [
    { name: 'Customer Name', rule: 'Required, 2 to 255 characters', note: 'Shown on BOQs, quotations and invoices.' },
    { name: 'Customer Type', rule: 'retail, wholesale, distributor or corporate', note: 'Defaults to retail. Affects the suggested rate only.' },
    { name: 'Contact Person', rule: 'Optional, up to 100 characters', note: '' },
    { name: 'Phone', rule: 'Required, 10-digit Indian mobile starting 6–9', note: 'Must be unique among your customers. Do not add +91.' },
    { name: 'WhatsApp', rule: 'Optional, 10-digit Indian mobile starting 6–9', note: 'Used for notifications in preference to Phone.' },
    { name: 'Email', rule: 'Optional, valid email, up to 100 characters', note: 'Must be unique among your customers.' },
    { name: 'GSTIN', rule: 'Optional, 15 characters, e.g. 27AAAAA0000A1Z5', note: 'Must be unique. First two digits are the state code.' },
    { name: 'PAN', rule: 'Optional, 10 characters, e.g. ABCDE1234F', note: '' },
    { name: 'Address Line 1', rule: 'Optional, up to 255 characters', note: '' },
    { name: 'City', rule: 'Optional, up to 100 characters', note: 'Shown next to the name in the customer picker.' },
    { name: 'State (GST)', rule: 'Optional, pick from the list', note: 'Sets CGST+SGST vs IGST on quotations and invoices.' },
    { name: 'Pincode', rule: 'Optional, 6 digits', note: '' },
    { name: 'Credit Limit (₹)', rule: 'Optional, 0 or more', note: '0 = no limit. Checked when an order is created.' },
    { name: 'Payment Terms (days)', rule: 'Optional, 0 to 365', note: 'Defaults to 30. For reference.' },
    { name: 'Notes', rule: 'Optional, up to 2000 characters', note: '' },
  ],

  statuses: [
    { name: 'Prospect', meaning: 'No orders yet.', next: 'New' },
    { name: 'New', meaning: 'Exactly one order.', next: 'Loyal, Champion' },
    { name: 'Champion', meaning: '4 or more orders, last one within 90 days.', next: 'Loyal, At Risk' },
    { name: 'Loyal', meaning: 'Repeat customer, last order within 180 days.', next: 'Champion, At Risk' },
    { name: 'At Risk', meaning: 'Repeat customer, last order 181 to 365 days ago.', next: 'Loyal, Dormant' },
    { name: 'Dormant', meaning: 'Repeat customer, no order for over a year.', next: 'Loyal, Champion' },
  ],

  cases: [
    {
      title: 'Customer is in another state',
      body: 'Pick their state in **State (GST)**. Quotations then show IGST @ 18% instead of CGST + SGST. The quotation form shows the GST type once the customer is chosen.',
    },
    {
      title: 'Customer has no GSTIN',
      body: 'Leave **GSTIN** blank and still pick **State (GST)**. If neither the state nor GSTIN is known, the system treats the sale as intra-state (CGST + SGST).',
    },
    {
      title: 'Order blocked by the credit limit',
      body: 'Creating the order shows the credit-limit message with the amount over. Collect payment, raise the limit, or ask an admin, who can choose **Override & create**.',
    },
    {
      title: 'Customer pays in advance, no credit',
      body: 'Leave **Credit Limit (₹)** at 0. Zero means no limit, so orders are never blocked for this customer.',
    },
    {
      title: 'Customer moved to a new state after quotations were made',
      body: 'Update **State (GST)**. Existing quotations keep their GST type until they are edited and saved again, when it is re-checked.',
    },
    {
      title: 'Same phone already used by another customer',
      body: 'Phone, email and GSTIN must be unique. Search the list first: the customer probably exists already. Edit that record instead of adding a duplicate.',
    },
    {
      title: 'Lead becomes a customer',
      body: 'Converting a lead creates or links the customer. Their leads then appear on the **Leads** tab of the profile.',
    },
  ],

  errors: [
    { message: 'Phone must be 10 digits.', fix: 'Type the 10-digit mobile number only, without +91 or 0.' },
    { message: 'Enter a valid Indian mobile number (starts with 6-9).', fix: 'Use a mobile number starting with 6, 7, 8 or 9.' },
    { message: 'Enter a valid 15-character GSTIN.', fix: 'Check the GSTIN on the customer\'s certificate, e.g. 27AAAAA0000A1Z5.' },
    { message: 'Enter a valid PAN (e.g. ABCDE1234F).', fix: 'Five letters, four digits, one letter.' },
    { message: 'Pincode must be 6 digits.', fix: 'Enter the 6-digit pincode.' },
    { message: 'Enter a valid email address.', fix: 'Correct the email, or leave it blank.' },
    { message: 'The phone has already been taken.', fix: 'Another customer has this phone. Search for them and edit that record. Same for email and GSTIN.' },
    { message: 'Credit limit exceeded for (customer). Exposure ₹… + this order ₹… would exceed the ₹… limit by ₹….', fix: 'Collect payment, raise the credit limit, or ask an admin to override.' },
    { message: 'Failed to refresh customers list.', fix: 'The customer was saved; reload the form and pick them from the list.' },
  ],

  tips: [
    'Always set **State (GST)**. It is the field that decides CGST + SGST or IGST.',
    'Search before adding, so you do not create the same customer twice.',
    'The **Outstanding** figure on the profile is live: unpaid invoice amounts after payments.',
    'Use the **Leads** and **Quotations** tabs on the profile to see the full sales history in one place.',
  ],

  access: 'Viewing customers needs customers view permission; adding or editing needs customers manage. Sales and Company Admin have both by default. Only admins can override a credit-limit block.',
}
