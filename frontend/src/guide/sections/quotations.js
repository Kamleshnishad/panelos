export default {
  key: 'quotations',
  title: 'Quotations',
  group: 'Sales',
  img: 'quotations',

  summary: 'Price panels, accessories and installation for a customer, print the quotation PDF, record the customer\'s answer and turn an accepted quotation into an order.',

  fits: 'Comes after a lead, a customer or a BOQ (BOQ Register, **Convert →**). An accepted quotation becomes an order, which then goes to production, dispatch and invoicing.',

  prereqs: [
    'The customer exists, with State filled in. The state decides CGST+SGST or IGST.',
    'Panel types exist in Master Data. Accessories you want to pick from a list exist there too.',
    'Your company GSTIN is saved under Company, so same-state or other-state can be worked out.',
  ],

  tasks: [
    {
      title: 'Create a quotation',
      steps: [
        'Open **Quotations** and click **+ New Quotation**.',
        'Pick the **Customer *** (or click **+ New** to add one). Fill **Project Name**, **Project Location** and, if goods go elsewhere, **Delivery Address**.',
        'Set **Quality Grade**, **Quotation Date**, **Validity (days)**, **Discount %** and **Advance %**.',
        'Add panel rows, sizes and rates (see below), then any accessories or installation.',
        'Check the **Summary** box, then click **Save Draft** or **Save & Send**.',
      ],
    },
    {
      title: 'Start a quotation from a BOQ',
      steps: [
        'Open **BOQ Register** and click **Convert →** on the BOQ.',
        'It becomes a Draft quotation with **Rates Pending** shown.',
        'Open it in **Quotations**, type a rate in each size row and click **Save Rates**.',
        'When no row is at 0, **Send** becomes available.',
      ],
    },
    {
      title: 'Add panel rows and sizes',
      steps: [
        'Click **+ Add Panel Row** and choose **Panel Type ***. Application, core and surface are guessed from the name; change them if needed.',
        'Set **Thickness (mm) ***, **Core / Insulation**, **Density (kg/m³)**, **Fixing System**, then **TOP SKIN** and **BOTTOM SKIN** (or tick **Same as top**).',
        'Tick **Guard Film** or **Cello Tap** if needed. **HSN Code** starts as 39259010.',
        'Under **SIZE BREAKDOWN** click **+ Size**, enter **Length (mm)** and **NOS**. Width is always 1000 mm; SQM is worked out for you.',
        'Use **Dup** to copy a row, or the cross to remove it.',
      ],
    },
    {
      title: 'Use the suggested rate',
      steps: [
        'Select the customer and the panel type first.',
        'A chip like **≈ 1450** appears next to each rate box.',
        'Click the chip to copy it into that size row, or type your own rate.',
        'The suggestion uses panel base price, thickness, skins, core, surface, customer type and quality grade. It is never filled in by itself.',
      ],
    },
    {
      title: 'Add accessories, doors and installation',
      steps: [
        'In **Accessories / Installation** click **+ Accessory** and pick an item, or leave **— Custom —** and type a description.',
        'Click **+ Door/Window** for a door, window, vision panel or pass-through; enter type, width, height, NOS and rate.',
        'Click **+ Installation** for erection charges (unit LS by default). Enter qty and rate.',
        'Amounts are qty times rate. Use **✕ Remove** to drop a line.',
      ],
    },
    {
      title: 'Set transport, discount and advance',
      steps: [
        'Under **Transportation** tick **Fixed Amount** and enter the rupee amount, or leave it unticked to print **Extra as Actual**.',
        '**Discount %** is taken off the subtotal before GST.',
        '**Advance %** splits the grand total into advance and balance on the documents and the order.',
      ],
    },
    {
      title: 'Open, download or print the PDF',
      steps: [
        'On the quotation page click **PDF** to open it, or the download arrow to save it.',
        'Click **BOQ Sheet** for the worker cutting sheet. It has no rates and no customer name.',
        'The PDF design is chosen in **Doc Templates** (Preview, then apply). The heading (Proforma Invoice or Quotation) is **Document title** under Company.',
      ],
    },
    {
      title: 'Send a quotation',
      steps: [
        'Open a Draft and click **Send**, or **Send** in the list and then **Confirm Send**.',
        'Send marks it Sent in PanelOS. It does not e-mail or WhatsApp the customer; download the PDF and share it yourself.',
        'If the quotation came from a lead, follow-up actions are planned on that lead automatically.',
      ],
    },
    {
      title: 'Record the customer\'s answer',
      steps: [
        'Customer agrees: open the Sent quotation and click **Accept**.',
        'Customer declines: click **Reject** (works on Draft or Sent).',
        'No answer and the offer has lapsed: click **Expire** (Draft or Sent). Nothing expires by itself.',
      ],
    },
    {
      title: 'Revise a quotation',
      steps: [
        'Open a Sent or Accepted quotation that has no order and click **Revise**.',
        'The old one is locked as Revised. A new Draft opens with the same rows and number ending -v2.',
        'Change it with **Edit**, then send it again. **Revision History** links all versions.',
      ],
    },
    {
      title: 'Duplicate a quotation',
      steps: [
        'Open any quotation and click **Duplicate**.',
        'A new Draft with a fresh number and today\'s date opens, with all rows copied. Change the customer or rows with **Edit**.',
      ],
    },
    {
      title: 'Create an order',
      steps: [
        'Open the Accepted quotation and click **Create Order**.',
        'If the customer is over their credit limit you see the reason. An admin can choose **Override & create**.',
        'You are taken to **Orders**, where the new order is Pending.',
      ],
    },
    {
      title: 'Delete a draft',
      steps: [
        'Open a Draft and click **Delete**, then **Delete** again to confirm.',
        'Only Drafts (and BOQs) can be deleted. Sent quotations are rejected or expired instead.',
      ],
    },
  ],

  fields: [
    { name: 'Customer', rule: 'Required. Must be one of your customers.', note: 'Their state sets CGST+SGST or IGST.' },
    { name: 'Project Name / Project Location', rule: 'Optional, up to 255 characters each.', note: '' },
    { name: 'Delivery Address', rule: 'Optional, up to 500 characters.', note: 'Only if different from the buyer address.' },
    { name: 'Site Contact / Site Contact Phone', rule: 'Optional. Name up to 100 characters; phone 6 to 20 digits, spaces, +, - or brackets.', note: '' },
    { name: 'Quality Grade', rule: 'High, Medium or Standard. Default High.', note: 'Lowers the suggested rate: Medium 3%, Standard 7%.' },
    { name: 'Quotation Date', rule: 'Optional date. Default today.', note: 'Printed on the PDF.' },
    { name: 'Validity (days)', rule: '1 to 365. Default 10.', note: 'Valid until is counted from the day you save, not from the Quotation Date.' },
    { name: 'Discount %', rule: '0 to 100. Default 0.', note: 'Taken off the subtotal before GST.' },
    { name: 'Advance %', rule: '0 to 100. Default 50.', note: 'Shown as advance and balance; it does not block production.' },
    { name: 'Transportation', rule: 'Fixed Amount: 0 or more.', note: 'Added after GST and not taxed. Unticked prints Extra as Actual.' },
    { name: 'Notes / Special Instructions', rule: 'Optional, up to 2000 characters.', note: '' },
    { name: 'Panel rows', rule: 'At least one row.', note: '' },
    { name: 'Panel Type', rule: 'Required on every row.', note: '' },
    { name: 'Thickness (mm)', rule: 'One of 10, 25, 30, 40, 50, 60, 75, 80, 100, 120, 150, 200.', note: '' },
    { name: 'Core / Insulation', rule: 'PUF, PIR, Rockwool, EPS or Glasswool.', note: '' },
    { name: 'Density (kg/m³)', rule: 'Required, 10 to 200.', note: '' },
    { name: 'Skin Material', rule: 'PPGI, PPGL, GI, SS304 or Aluminium.', note: '' },
    { name: 'Skin Thickness (mm)', rule: '0.30, 0.35, 0.40, 0.45, 0.50 or 0.60.', note: '' },
    { name: 'Surface', rule: 'Plain or Ribbed.', note: 'Ribbed top adds ₹30 to the suggested rate.' },
    { name: 'Color / RAL Code', rule: 'Optional. Color up to 50 characters, RAL up to 20.', note: '' },
    { name: 'HSN Code', rule: 'Optional, up to 20 characters.', note: 'Starts as 39259010.' },
    { name: 'Length (mm)', rule: 'Required, whole number 500 to 14000.', note: 'Under 2000 mm is marked DL: made at double length and cut.' },
    { name: 'Width (mm)', rule: 'Fixed at 1000.', note: 'Other widths are not supported.' },
    { name: 'NOS', rule: 'Required, whole number, at least 1.', note: 'SQM = length in metres x NOS.' },
    { name: 'Rate (₹/SQM)', rule: '0 or more. Must be above 0 on every row before sending.', note: 'The quotation form also needs it for Save Draft. 0 means rate pending.' },
    { name: 'Accessory Qty / Rate', rule: '0 or more.', note: 'Description up to 255 characters, Unit up to 20.' },
  ],

  statuses: [
    { name: 'Draft', meaning: 'Being prepared. Can be edited, priced, sent or deleted.', next: 'Sent, Rejected, Expired' },
    { name: 'Sent', meaning: 'Marked as given to the customer. Rates can still be changed on the page; use Revise instead.', next: 'Accepted, Rejected, Expired, Revised' },
    { name: 'Accepted', meaning: 'Customer agreed. Locked against edits. Ready for Create Order.', next: 'Order created; Revised only while no order exists' },
    { name: 'Rejected', meaning: 'Customer declined. Closed.', next: 'None. Use Duplicate to start again.' },
    { name: 'Expired', meaning: 'Marked lapsed by a user. Closed.', next: 'None. Use Duplicate to quote again.' },
    { name: 'Revised', meaning: 'Replaced by a newer version (-v2 and so on). Locked.', next: 'None. Work on the newest version.' },
  ],

  cases: [
    { title: 'Customer in another state', body: 'If the customer\'s state differs from your GSTIN state, 18% IGST is used; same state gives CGST 9% + SGST 9%. If the customer has no state, it is treated as same-state.' },
    { title: 'Customer\'s state was wrong', body: 'Correct the state on the customer, then open the Draft, click **Edit** and save again so GST is worked out afresh. For a Sent quotation, use **Revise** and save the new version.' },
    { title: 'Customer wants a lower rate after sending', body: 'Click **Revise**, lower the rates in the new Draft and send it again. The old version stays on record as Revised.' },
    { title: 'Customer accepts only part of the quotation', body: 'Partial acceptance is not supported. Revise, remove the rows they did not take, send the new version and accept that.' },
    { title: 'Validity date has passed', body: 'Nothing expires by itself and Accept still works. If prices must change, Revise; otherwise click **Expire** and Duplicate when they come back.' },
    { title: 'Rates not ready yet', body: 'The quotation form will not save rows at rate 0. Save it as a BOQ in **BOQ Register** instead, then convert and price it later.' },
    { title: 'Accepted quotation needs a change', body: 'If no order exists yet, click **Revise**, edit, send and accept the new version. Once an order exists, the quotation cannot be revised.' },
    { title: 'Same job for a new customer', body: 'Click **Duplicate**, then **Edit** the copy and change the customer. The copy gets a fresh number and today\'s date.' },
    { title: 'Order blocked by credit limit', body: 'Collect payment first, or ask an admin. An admin clicking **Create Order** gets **Override & create**; others see the reason and must ask an admin.' },
  ],

  errors: [
    { message: 'Please select a customer.', fix: 'Choose a customer at the top of the form.' },
    { message: 'Add at least one panel row.', fix: 'Click **+ Add Panel Row**.' },
    { message: 'Row N: select a panel type.', fix: 'Pick a **Panel Type** on that row.' },
    { message: 'Row N: add at least one size entry.', fix: 'Click **+ Size** on that row.' },
    { message: 'Row N: length must be at least 500mm.', fix: 'Enter a length of 500 mm or more.' },
    { message: 'Row N: length cannot exceed 14000mm.', fix: 'Split the length; the maximum is 14000 mm.' },
    { message: 'Row N: quantity (Nos) must be at least 1.', fix: 'Enter NOS of 1 or more.' },
    { message: 'Row N: enter a rate per SQM before sending. Save as BOQ if rates aren\'t ready.', fix: 'Type a rate above 0 on every size row, or save it as a BOQ instead.' },
    { message: 'Row N: density (kg/m³) must be between 10 and 200.', fix: 'Correct the density.' },
    { message: 'Rates are pending. Enter rates for all panel rows before sending.', fix: 'Type a rate in every size row, click **Save Rates**, then **Send**.' },
    { message: 'Cannot send a quotation with no panel items.', fix: 'Edit the draft and add at least one panel row.' },
    { message: 'Only draft quotations can be sent.', fix: 'It was already sent or closed. Refresh the page.' },
    { message: 'Only BOQs, draft or sent quotations can be edited.', fix: 'Accepted and closed quotations are locked. Use **Revise** or **Duplicate**.' },
    { message: 'Only sent quotations can be accepted.', fix: 'Send the draft first.' },
    { message: 'Cannot reject quotation in current status.', fix: 'Only Draft or Sent quotations can be rejected.' },
    { message: 'Only draft or sent quotations can be expired.', fix: 'It is already closed.' },
    { message: 'Only BOQs or draft quotations can be deleted.', fix: 'Reject or expire it instead.' },
    { message: 'This quotation already has an order and can no longer be revised.', fix: 'Change the order instead, or Duplicate for new work.' },
    { message: 'Cannot revise a quotation in \'revised\' status.', fix: 'Open the newest version from **Revision History** and revise that.' },
    { message: 'An order already exists for this quotation.', fix: 'Open it in **Orders**. One quotation gives one order.' },
    { message: 'Credit limit exceeded for (customer). Exposure ₹X + this order ₹Y would exceed the ₹Z limit by ₹W.', fix: 'Collect payment, raise the limit on the customer, or ask an admin to override.' },
  ],

  tips: [
    'After sending, do not change rates in place. Use **Revise** so the customer\'s copy and PanelOS match.',
    'Check **Valid until** after every save; it is counted from the save day, not the Quotation Date.',
    'Open the PDF and check every page before sharing it with the customer.',
    'For a rate-less sheet use **BOQ Sheet**, not **PDF**; the PDF of an unpriced quotation shows zero amounts.',
    'Double-check **Discount %**: anything up to 100% is accepted.',
    'Fixed transport is added after GST and not taxed on the quotation.',
    'CGST and SGST lines can differ from the GST total by one paisa. The grand total is correct.',
    'To create an order, open the quotation and use **Create Order**. The list\'s **Order** button only opens the Orders page.',
    'Typing a very large NOS or rate by mistake can give an error with a reference number. Check for extra zeros.',
  ],

  access: 'Viewing needs "View quotations / BOQ". Creating, editing, sending, accepting, revising and creating the order need "Create / edit quotations, enter rates". Only admins can override a credit limit.',
}
