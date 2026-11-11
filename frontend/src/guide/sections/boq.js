export default {
  key: 'boq',
  title: 'BOQ Register',
  group: 'Sales',
  img: 'boq',

  summary: 'A BOQ (Bill of Quantities) is the technical list for a job without rates: customer, project, panel rows with full spec, and lengths with NOS. When it is ready, Sales converts it into a quotation and enters the rates.',

  fits: 'Optional step between Customers and Quotations. You can price a job from a BOQ, or create a quotation directly in Quotations; both paths are fine. A converted BOQ becomes a draft quotation with the same number.',

  prereqs: [
    'The customer must exist (you can also add one from the form with **+ New**).',
    'At least one panel type in Settings → Master Data. Every panel row needs one.',
    'Accessories in Master Data only if you want to pick them from the list.',
  ],

  tasks: [
    {
      title: 'Create a BOQ',
      steps: [
        'Open **BOQ Register** and click **+ Add BOQ**. The form shows the tag **BOQ — no rates**.',
        'Under **BOQ Details**, choose the **Customer** and fill **Project Name**, **Project Location** and site details as needed.',
        'Add the panel rows and sizes (see below), and any accessories.',
        'Click **Save BOQ**. It appears in the register with a number such as SCP-2027-001.',
      ],
    },
    {
      title: 'Make a BOQ from a lead',
      steps: [
        'On the lead, add its requirement lines, then click **Make a BOQ instead**.',
        'Each panel line becomes a row with one size of 3000 mm, enough NOS to cover the area.',
        'Open the BOQ, replace the sizes with the real lengths and NOS, and save.',
      ],
    },
    {
      title: 'Add panel rows and sizes',
      steps: [
        'Under **Panel Specification Rows**, click **+ Add Panel Row**.',
        'Pick **Panel Type** and **Thickness (mm)**; set **Application**, **Core / Insulation**, **Density (kg/m³)** and **Fixing System**.',
        'Set **TOP SKIN** material, thickness, colour, RAL and surface. Untick **Same as top** to give the bottom skin its own spec.',
        'Tick **Guard Film** or **Cello Tap** if needed and check the **HSN Code**.',
        'Under **SIZE BREAKDOWN**, click **+ Size** and enter **Length (mm)** and **NOS**. Width is always 1000 mm; SQM is worked out for you.',
        'Use **Dup** to copy a row with a similar spec, or ✕ to remove a row.',
      ],
    },
    {
      title: 'Add accessories and doors',
      steps: [
        'Under **Accessories / Installation**, click **+ Accessory** and pick an **Item** from the list, or **— Custom —** and type a **Description**.',
        'Enter **Qty** and **Unit**.',
        'For doors and windows click **+ Door/Window**, then choose the type, **Width (mm)**, **Height (mm)** and **NOS**.',
        'Installation charges are added on the quotation, not on a BOQ.',
      ],
    },
    {
      title: 'Edit a BOQ',
      steps: [
        'Click the BOQ row, or **Edit** in its Actions.',
        'Change header, rows, sizes or accessories, then click **Update BOQ**. The saved rows are replaced by what is on screen.',
        'If you leave with unsaved changes, you are asked before the form closes.',
      ],
    },
    {
      title: 'Download the BOQ sheet (PDF)',
      steps: [
        'Click **Sheet** on the BOQ row. The same sheet is available as **BOQ Sheet** on the quotation page later.',
        'It prints each row\'s spec and a cutting table (Sr, Length, Width, NOS, SQM) with row totals.',
        'It has no rates and no customer name, so it is safe to give to the shop floor.',
      ],
    },
    {
      title: 'Convert a BOQ to a quotation',
      steps: [
        'Click **Convert →** on the BOQ row and confirm with **Convert**.',
        'The BOQ leaves the register and opens in Quotations as a draft with the same number.',
        'It shows **Rates Pending**. Enter a rate per SQM for every size row and click **Save Rates**, or use **Edit**.',
        '**Send** stays disabled until every panel row has a rate.',
      ],
    },
  ],

  fields: [
    { name: 'Customer', rule: 'Required', note: 'Decides CGST + SGST or IGST, shown under the header.' },
    { name: 'Project Name', rule: 'Optional, up to 255 characters', note: '' },
    { name: 'Project Location', rule: 'Optional, up to 255 characters', note: '' },
    { name: 'Delivery Address', rule: 'Optional, up to 500 characters', note: 'Only if different from the buyer.' },
    { name: 'Site Contact', rule: 'Optional, up to 100 characters', note: '' },
    { name: 'Site Contact Phone', rule: 'Optional, 6 to 20 digits; spaces, +, - and brackets allowed', note: '' },
    { name: 'Quality Grade', rule: 'High, Medium or Standard', note: 'Defaults to High. Used for the suggested rate later.' },
    { name: 'Quotation Date', rule: 'Optional date', note: 'Defaults to today.' },
    { name: 'Validity (days)', rule: '1 to 365', note: 'Defaults to 10. Valid-until is counted from the day you save.' },
    { name: 'Notes / Special Instructions', rule: 'Optional, up to 2000 characters', note: '' },
    { name: 'Panel Type', rule: 'Required on every row', note: 'From Master Data.' },
    { name: 'Application', rule: 'Optional: Wall, Roof, Cold Room, Partition, Clean Room, Ceiling, PEB Shade, Architectural', note: '' },
    { name: 'Thickness (mm)', rule: 'Required: 10, 25, 30, 40, 50, 60, 75, 80, 100, 120, 150 or 200', note: 'Defaults to 50.' },
    { name: 'Core / Insulation', rule: 'PUF, PIR, Rockwool, EPS or Glasswool', note: 'Defaults to PUF.' },
    { name: 'Density (kg/m³)', rule: 'Required, 10 to 200', note: 'Defaults to 40.' },
    { name: 'Fixing System', rule: 'Optional: Cam-Lock, Secret-Fix, Standing-Seam, Lap-Joint, Visible-Fix', note: '' },
    { name: 'Skin Material', rule: 'PPGI, PPGL, GI, Stainless Steel 304 or Aluminium', note: 'Top and bottom. Defaults to PPGI.' },
    { name: 'Skin Thickness (mm)', rule: '0.30, 0.35, 0.40, 0.45, 0.50 or 0.60', note: 'Defaults to 0.40.' },
    { name: 'Color / RAL Code', rule: 'Optional, up to 50 / 20 characters', note: 'Colour defaults to Off White.' },
    { name: 'Surface', rule: 'Plain or Ribbed', note: '' },
    { name: 'HSN Code', rule: 'Optional, up to 20 characters', note: 'Defaults to 39259010.' },
    { name: 'Length (mm)', rule: 'Required, 500 to 14000', note: 'Under 2000 mm is marked DL.' },
    { name: 'NOS', rule: 'Required, whole number, at least 1', note: '' },
    { name: 'Door/Window Type', rule: 'Sliding Door, Hinged Door, Window / Vision Panel, Pass-through Opening', note: 'Width and Height in mm.' },
  ],

  statuses: [
    { name: 'BOQ', meaning: 'Rate-less list in the BOQ Register; can be edited.', next: 'Draft (by Convert)' },
    { name: 'Draft', meaning: 'Now a quotation in Quotations; Sales enters rates.', next: 'Sent, Rejected, Expired' },
  ],

  cases: [
    {
      title: 'Panels shorter than 2000 mm',
      body: 'Enter the real length. The size row shows a DL marker: the panel is made at double length and cut after foaming. The BOQ sheet flags DL so the shop floor sees it.',
    },
    {
      title: 'Client revises the drawing before pricing',
      body: 'Open the BOQ, change the rows or sizes, and click **Update BOQ**. No new number is created while it is still a BOQ.',
    },
    {
      title: 'Same panel spec in many lengths',
      body: 'Keep one panel row and add a size line for each length with **+ Size**. Use **Dup** only when the spec itself changes, such as another thickness.',
    },
    {
      title: 'Customer is in another state',
      body: 'Nothing to do on the BOQ. Once the customer is chosen, the header shows IGST (Inter-state); the quotation uses it after conversion.',
    },
    {
      title: 'Rates are ready from the start',
      body: 'Skip the BOQ and create the quotation directly in Quotations. On that form every size row needs a rate before saving.',
    },
    {
      title: 'Rates are not ready, but the sizes are',
      body: 'Save it as a BOQ. The quotation form will not save without rates, and suggests saving as a BOQ instead.',
    },
    {
      title: 'Converted too early and need more changes',
      body: 'The quotation can still be edited while it is a draft or sent. Use **Edit** on the quotation; it does not go back to the BOQ Register.',
    },
    {
      title: 'Accessory picked from the master list',
      body: 'Its master rate is carried quietly into the BOQ and shows on the quotation after conversion. Check it there.',
    },
  ],

  errors: [
    { message: 'Please select a customer.', fix: 'Pick a customer, or add one with **+ New**.' },
    { message: 'Add at least one panel row.', fix: 'Click **+ Add Panel Row**.' },
    { message: 'Row N: select a panel type.', fix: 'Choose **Panel Type** on that row. If the list is empty, add panel types in Master Data.' },
    { message: 'Row N: add at least one size entry.', fix: 'Click **+ Size** on that row and enter length and NOS.' },
    { message: 'Row N: length must be at least 500mm.', fix: 'Enter a length of 500 mm or more.' },
    { message: 'Row N: length cannot exceed 14000mm.', fix: 'Split it into lengths of 14000 mm or less.' },
    { message: 'Row N: quantity (Nos) must be at least 1.', fix: 'Enter NOS of 1 or more, or remove the size line.' },
    { message: 'Row N: density (kg/m³) must be between 10 and 200.', fix: 'Correct the density.' },
    { message: 'Row N: enter a rate per SQM before sending. Save as BOQ if rates aren\'t ready.', fix: 'Shown on the quotation form. Enter rates, or make a BOQ instead.' },
    { message: 'Cannot convert a BOQ with no panel items.', fix: 'Edit the BOQ, add at least one panel row, then convert.' },
    { message: 'Only a BOQ can be converted to a quotation.', fix: 'It was already converted. Find it in Quotations.' },
    { message: 'Only BOQs, draft or sent quotations can be edited.', fix: 'Accepted or closed quotations are locked. Use Revise or Duplicate in Quotations.' },
    { message: 'Rates are pending. Enter rates for all panel rows before sending.', fix: 'Enter a rate for every size row on the quotation, save, then send.' },
  ],

  tips: [
    'Fill one row fully, then use **Dup** for similar rows and change only what differs.',
    'Check the **BOQ Summary** (panel rows, total NOS, total SQM) before saving.',
    'Give the shop floor the BOQ sheet, never the quotation PDF: it has no rates.',
    'Search the register by BOQ number, project or customer.',
  ],

  access: 'BOQs follow quotation permissions: viewing needs quotations view, creating, editing and converting need quotations manage. Sales and Company Admin have both by default.',
}
