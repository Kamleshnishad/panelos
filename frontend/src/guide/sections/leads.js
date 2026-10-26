export default {
  key: 'leads',
  title: 'Leads',
  group: 'Sales',
  img: 'leads',

  summary: 'Every enquiry, from a call, IndiaMART, your website or a spreadsheet, kept as a lead with its owner, follow-ups, timeline and files until it is quoted, won or lost.',

  fits: 'Leads come first. A lead is converted to a Customer, then quoted (Quotation or BOQ). When an Order is created from that quotation, the lead is marked Won by itself.',

  prereqs: [
    'Your role needs permission to view leads; adding or changing them needs permission to manage leads.',
    'Sources and lost reasons come from Master Data → Lead lists (a starter list is already there).',
    'To quote from a lead you need panel types (and accessories, if used) in Master Data.',
    'To send e-mails from a lead, a company mailbox must be set up in Customer Messaging.',
    'Optional: IndiaMART key, website form and auto-assignment rules in Master Data → Lead intake.',
  ],

  tasks: [
    {
      title: 'Add a lead by hand',
      steps: [
        'Click **+ New Lead**.',
        'Fill **Contact Name** (required) and at least a **Phone** or **Email** so you can reach them.',
        'Pick the **Source**, **Application** and, if you know it, **Assigned To**.',
        'Write what they asked for in **Requirement / Inquiry** and set a **Next Follow-up** date.',
        'Click **Create Lead**. The lead gets a number like LEAD-2026-0001.',
        'If a box says the buyer already exists, open that lead instead, or click **Create anyway**.',
      ],
    },
    {
      title: 'Find leads: views, filters and search',
      steps: [
        'Use the view chips: **All leads**, **My leads**, **My overdue**, **Due today**, **Unassigned**, **Waiting for a reply**, **Hot leads**, **Quiet leads** (and **New from IndiaMART** if you use it).',
        'Narrow down with the Source, Assigned to, Follow-up and Temperature drop-downs, and the sort order.',
        'Type a name, company, lead number, phone or e-mail in the search box and press Enter.',
        'Use the status tabs (All, New, Contacted … Won, Lost, Invalid) on the list. Invalid leads show only in their own tab.',
        'Click **Clear filters** to see everything again.',
      ],
    },
    {
      title: 'Save your own view',
      steps: [
        'Set the filters and sort you want.',
        'Click **Save this view**, type a name and click **Save**. Saving with an existing name replaces it.',
        'Your views are private to you, up to 20. Click the ✕ on a view chip to delete it; no lead is touched.',
      ],
    },
    {
      title: 'Work from the Board',
      steps: [
        'Click **Board** to see one column per open stage plus On hold, with counts and total sqm and value.',
        'Drag a card to another column, or use its **Move…** menu.',
        'While dragging, drop on **Drop here: mark lost** or **Drop here: junk** to close it; you will be asked for the reason.',
        'Each column shows 25 cards; click **+ N more: show them in the list** for the rest.',
      ],
    },
    {
      title: 'Plan and finish next actions (follow-ups)',
      steps: [
        'Open the lead. Under **Next actions** click **+ Plan an action**.',
        'Choose the kind (Call, WhatsApp, E-mail, Site visit, Send quotation, Other), the day, an optional time and who does it.',
        'Click **Plan it**. The lead\'s follow-up date becomes the earliest open action.',
        'When it is done click **Done**, write what happened, choose what is next, then **Mark done and plan next** (or **Mark done**).',
        'Use **Change** to move it, or ✕ to remove it.',
      ],
    },
    {
      title: 'Use My day',
      steps: [
        'Click **My day** (the number on it is what is due).',
        'Actions are grouped as overdue, today and the next 7 days.',
        'Tap the phone number to call, the lead to open it, or **Done** to finish the action.',
        'Managers can switch **Whose day** to Everyone, Unassigned or one person.',
      ],
    },
    {
      title: 'Log a call, visit or note',
      steps: [
        'Open the lead and go to **Log activity**.',
        'Choose Note, Call, Email, WhatsApp or Meeting, type what happened, click **Add**.',
        'After using **📞 Call**, the screen asks **How did the call go?**: pick an outcome, add a note, click **Save on the timeline**.',
      ],
    },
    {
      title: 'Send a WhatsApp or e-mail from a template',
      steps: [
        'On the lead click **💬 WhatsApp** or **✉ E-mail** (greyed out until the lead has a phone or e-mail).',
        'Pick a ready-made message under **Start from a ready-made message**, or choose **Write my own**. Edit the text.',
        'WhatsApp: click **Open WhatsApp**; WhatsApp opens with the text and you press send there.',
        'E-mail: give a subject and click **Send e-mail**. It goes now, from your company mailbox, to the lead\'s saved address.',
        'Both are written on the timeline. Templates are edited in Master Data → Lead messages.',
      ],
    },
    {
      title: 'Move a lead through the stages',
      steps: [
        'Open the lead. Under **Move to** click the next stage (for example **Contacted** or **Qualified**).',
        '**On hold** asks for **Comes back on**; the lead returns to your follow-ups on that day.',
        '**Lost** asks for a **Reason** from your list and an optional note.',
        '**Invalid** is for spam or wrong numbers; add a note why.',
        'Every move is written on the timeline.',
      ],
    },
    {
      title: 'Reopen a closed lead',
      steps: [
        'Open the Won, Lost or Invalid lead.',
        'Click **Reopen as …** with the stage you want.',
        'Write **Why are you reopening this lead?** (required) and click **Save**. The lost reason is cleared.',
      ],
    },
    {
      title: 'Record what they want and quote it',
      steps: [
        'On the lead, under **What they want**, click **+ Add the panels and accessories**.',
        'Add **+ Panel line** rows (panel type, application, thickness, area in sqm) and **+ Accessory** rows (pick one or type it, with qty). Up to 30 lines.',
        'Click **Save**. The panel area fills the lead\'s Est. Qty.',
        'Click **Quote this lead** (or **Make a BOQ instead**). If there is no customer yet, the convert box asks for the state first.',
        'A draft opens with rates at zero and standard lengths. Check sizes and enter rates before sending.',
      ],
    },
    {
      title: 'Convert to customer and quotation',
      steps: [
        'Open an open lead and click **→ Convert to Quotation**.',
        'Choose the **State** (or type the **GSTIN**, which fills the state) and an optional address. Click **Convert**.',
        'If similar customers exist, click **Use this customer**, or **None of these: create a new customer**.',
        'The quotation form opens with the customer filled in. Saving it moves the lead to Quoted and links it.',
      ],
    },
    {
      title: 'Merge a duplicate lead',
      steps: [
        'Open the lead you want to keep and click **Merge another lead into this one…**.',
        'Leads of the same buyer (same phone or e-mail) are listed first; or search by number, name, company or phone.',
        'Click **Merge this into …**. Its timeline, actions, files and messages move over, blanks are filled, and the other lead is removed.',
        'This cannot be undone.',
      ],
    },
    {
      title: 'Change many leads at once',
      steps: [
        'In the list, tick the leads (or the top box for the whole page). Up to 200 at a time.',
        '**Assign**: choose a person or **Nobody (unassign)**, click **Assign**.',
        '**Move to**: choose a stage, On hold (with date), Lost (with reason) or Invalid, click **Move**.',
        '**Plan an action**: choose kind, day and person, click **Plan it**.',
        'The result shows how many were done and why any were skipped (for example closed leads).',
      ],
    },
    {
      title: 'Import leads from a spreadsheet',
      steps: [
        'Click **Import**. In Excel save the sheet as CSV first (File → Save As → CSV). **Download the template** shows the headings.',
        'Choose the file and the source for rows without a Source column. Click **Check file**; nothing is imported yet.',
        'Review: New leads, Added to a lead the buyer already has, Already had, Cannot import, with the reason per row.',
        'Click **Import N new leads**. Assignment rules apply and each person gets one alert.',
        'Wrong file? Open **Import** again and click **Undo this import**.',
      ],
    },
    {
      title: 'Add drawings, photos and files',
      steps: [
        'On the lead, under **Files**, choose a kind (or leave **Kind: automatic**) and click **+ Add a file**.',
        'Allowed: pdf, jpg, png, webp, dwg, dxf, xls, xlsx, csv, doc, docx. Up to 15 MB each, 30 files per lead.',
        'Click a file name to open or download it; ✕ removes it.',
      ],
    },
    {
      title: 'Export the list',
      steps: [
        'Pick the status tab and type any search.',
        'Click **Export**. A CSV with the matching leads downloads; it can be imported again later.',
      ],
    },
    {
      title: 'Bring in IndiaMART and website enquiries (admin)',
      steps: [
        'Go to Master Data → **Lead intake**.',
        'IndiaMART: paste the CRM key, click **Save key**, **Test connection**, then switch on **Import automatically every 10 minutes**. Use **Pull a date range** for missed days (up to 7 days).',
        'Website or partner: make an inbound address with **Make address**, and use the **Website form** code on your site.',
        'Set **Who gets a new lead** rules so new leads are shared out in turn. Without rules, new leads stay unassigned.',
      ],
    },
    {
      title: 'See how sales is doing',
      steps: [
        'Click **📊 Insights**.',
        'See totals, win rate, the funnel, leads and conversion by source, follow-ups due, response time and top lost reasons.',
        'Click the Hot, Warm or Cold tiles, or the waiting and quiet numbers, to open those leads.',
      ],
    },
  ],

  fields: [
    { name: 'Contact Name', rule: 'Required, 2 to 100 characters', note: 'The person who enquired.' },
    { name: 'Company', rule: 'Optional, up to 150 characters', note: '' },
    { name: 'Phone', rule: 'Optional, digits only, up to 10', note: 'Used to spot the same buyer (last 10 digits) and for WhatsApp.' },
    { name: 'Email', rule: 'Optional, must be a valid e-mail', note: 'Also used to spot the same buyer; needed for sending e-mails.' },
    { name: 'City', rule: 'Optional, up to 60 characters', note: 'Copied to the customer on convert; can drive assignment rules.' },
    { name: 'Source', rule: 'One of your active sources', note: 'List is kept in Master Data → Lead lists.' },
    { name: 'Application', rule: 'Wall, Roof, Cold Room, Partition, Clean Room, Ceiling, PEB Shade or Architectural', note: '' },
    { name: 'Assigned To', rule: 'Optional, a user of your company', note: 'Left empty, assignment rules (if any) pick someone. They get a bell alert.' },
    { name: 'Est. Qty (SQM)', rule: 'Optional, 0 or more', note: 'Filled from the panel lines when you save What they want.' },
    { name: 'Est. Value (₹)', rule: 'Optional, 0 or more', note: 'Counts towards the lead score.' },
    { name: 'Next Follow-up', rule: 'Optional date', note: 'Once a lead has open actions, the earliest action date is used instead.' },
    { name: 'Requirement / Inquiry', rule: 'Optional, up to 1000 characters', note: 'Free text of what they asked for.' },
    { name: 'Notes', rule: 'Optional, up to 1000 characters', note: 'Internal notes.' },
    { name: 'Comes back on (On hold)', rule: 'Required, today or later', note: 'Becomes the follow-up date.' },
    { name: 'Reason (Lost)', rule: 'Required, from your lost-reason list', note: 'Plus an optional note.' },
    { name: 'Why are you reopening this lead?', rule: 'Required, up to 500 characters', note: 'Written on the timeline.' },
    { name: 'Next action day', rule: 'Required, today or later; time optional (HH:MM)', note: 'Only on open or on-hold leads.' },
    { name: 'Panel line', rule: 'Panel type, thickness (10 to 200 mm list) and area over 0 sqm required', note: 'Application optional. Up to 30 lines in total.' },
    { name: 'State (Convert)', rule: 'Required for a new customer, or give a GSTIN', note: 'Decides CGST + SGST or IGST on the quotation.' },
  ],

  statuses: [
    { name: 'New', meaning: 'Just arrived; nobody has worked it yet.', next: 'Any open stage, On hold, Won, Lost, Invalid' },
    { name: 'Contacted', meaning: 'You have spoken to or messaged the buyer.', next: 'Any open stage, On hold, Won, Lost, Invalid' },
    { name: 'Qualified', meaning: 'A real requirement worth quoting.', next: 'Any open stage, On hold, Won, Lost, Invalid' },
    { name: 'Quoted', meaning: 'A quotation or BOQ was made for it. Set by the system when the quotation is saved.', next: 'Any open stage, On hold, Won, Lost, Invalid' },
    { name: 'On hold', meaning: 'Waiting until a set date; comes back as a follow-up that day.', next: 'Any open stage, Won, Lost, Invalid' },
    { name: 'Won', meaning: 'Set by the system when an order is created from its quotation (or by hand). Open actions are cancelled.', next: 'Reopen to an open stage (reason needed)' },
    { name: 'Lost', meaning: 'Closed with a reason from your list. Open actions are cancelled.', next: 'Reopen to an open stage (reason needed)' },
    { name: 'Invalid', meaning: 'Junk: spam, wrong number. Kept but left out of counts and reports.', next: 'Reopen to an open stage (reason needed)' },
  ],

  cases: [
    { title: 'The same buyer calls again and you start a new lead', body: 'The form warns "This looks like a buyer you already have" (same phone or e-mail). Open that lead and log the call. Use **Create anyway** only if it is truly a new project.' },
    { title: 'A repeat enquiry arrives from IndiaMART, the website or an import', body: 'If the buyer has an open or on-hold lead, no new lead is made. An "Enquired again" note is added and its follow-up comes to today. Its owner gets a bell.' },
    { title: 'A new enquiry from a buyer you lost earlier', body: 'A fresh lead is made and its first note names the old lead. The old lead is not reopened. Read the old lost reason before calling.' },
    { title: 'Two leads already exist for one buyer', body: 'Open the one to keep and use **Merge another lead into this one…**. Both must be open or on hold, and at most one may have a quotation.' },
    { title: 'A lead goes cold', body: 'It shows a "quiet N d" chip and appears in **Quiet leads**. Plan a call, or put it **On hold** with a return date so it comes back by itself.' },
    { title: 'Lost to price', body: 'Click **Lost** and choose the price reason from the list, with the competitor rate in the note. Top lost reasons show in **📊 Insights**. If they come back, **Reopen as** with a reason.' },
    { title: 'A salesperson leaves', body: 'Filter Assigned to that person, tick all leads, use **Assign** in the bar to the new owner. Closed leads are skipped. Also remove them from assignment rules in Lead intake.' },
    { title: 'Converting when the customer already exists', body: 'The convert box lists look-alike customers (same phone, e-mail, GSTIN or name). Click **Use this customer**. Choose **None of these** only for a genuinely new party.' },
    { title: 'The customer places an order', body: 'Create the order from the quotation as usual. The lead becomes Won by itself, even if the order came from a revised quotation.' },
    { title: 'You sent a quotation and need to chase it', body: 'The system plans "auto" actions: a call 3 days after sending, a WhatsApp at 7 days, a call 2 days before expiry. They are cancelled once the quotation is accepted, rejected or revised.' },
    { title: 'You imported the wrong file', body: 'Open **Import** and click **Undo this import**. Only the latest import, once. Leads someone already worked on are kept and counted in the message.' },
  ],

  errors: [
    { message: 'This looks like a buyer you already have', fix: 'Open the listed lead, or click **Create anyway** / **Save anyway** if it is a separate enquiry.' },
    { message: 'A lead cannot go from won to contacted. Reopen it first.', fix: 'Use **Reopen as …** and give a reason.' },
    { message: 'Say why you are reopening this lead.', fix: 'Type the reason in the box, then **Save**.' },
    { message: 'Choose the date this lead comes back (today or later).', fix: 'Pick a **Comes back on** date from today onwards.' },
    { message: 'Choose why this lead was lost.', fix: 'Pick a **Reason** from the list. Add new reasons in Master Data → Lead lists.' },
    { message: 'This buyer looks like a customer you already have. Use that customer, or create a new one anyway.', fix: 'Click **Use this customer**, or **None of these: create a new customer**.' },
    { message: 'Choose the customer\'s state (or enter the GSTIN), so GST is split correctly on the quotation.', fix: 'Select the **State** or type the GSTIN.' },
    { message: 'A customer with this GSTIN already exists. Use that customer instead.', fix: 'Close the box, convert again and pick that customer from the list.' },
    { message: 'The GSTIN belongs to Gujarat, not Maharashtra.', fix: 'Correct the state or the GSTIN so they agree.' },
    { message: 'This lead is closed. Reopen it to plan an action.', fix: 'Reopen the lead first; closed leads take no actions.' },
    { message: 'Choose today or a later day.', fix: 'Actions cannot be planned in the past.' },
    { message: 'Add at least one panel line (panel type, thickness and area) to the requirement first.', fix: 'Fill **What they want** with a panel line, then **Quote this lead**.' },
    { message: 'You need permission to create quotations to do this.', fix: 'Ask an admin for quotation rights, or ask a colleague to quote it.' },
    { message: 'This lead has no phone number WhatsApp can use. Add the number with its 10 digits.', fix: '**Edit** the lead and correct the phone.' },
    { message: 'E-mail is not set up for your company yet. Add your mailbox in Customer Messaging, then try again.', fix: 'Ask an admin to set up the company mailbox.' },
    { message: 'A lead can keep 30 files. Remove one first.', fix: 'Delete an old file with ✕, then upload.' },
    { message: 'The file is larger than 15 MB.', fix: 'Compress the PDF or photo, or split it.' },
    { message: 'This does not look like a real .pdf file.', fix: 'The file is damaged or renamed. Save it again in the right format.' },
    { message: 'Both leads already have a quotation, and a lead follows one quotation. Merge only when at most one of them has been quoted.', fix: 'Keep both leads, or mark one Invalid with a note.' },
    { message: 'The first row of the file must have headings such as Name, Phone and Email. Download the template to see them.', fix: 'Add a heading row, or start from the template.' },
    { message: 'Only the most recent import can be undone.', fix: 'Older imports cannot be undone; fix or delete those leads by hand.' },
    { message: 'You can keep 20 views. Delete one first.', fix: 'Remove a saved view with its ✕.' },
    { message: 'That lead was not found.', fix: 'It was deleted or merged, or it is assigned to someone else and you can see only your own.' },
  ],

  tips: [
    'Always plan a next action before leaving a lead. A lead with nothing planned soon shows as quiet.',
    'Start each morning in **My day**: overdue first, then today.',
    'Fill **What they want** properly; **Quote this lead** then builds the draft for you.',
    'Do not click Won by hand for normal sales; creating the order marks it won and keeps reports honest.',
    'Use Invalid for spam and wrong numbers, not Lost, so your win rate stays true.',
    'Hot, Warm and Cold come from a 0 to 100 score: stage, size, recent activity, contact details and a planned follow-up.',
    'A Delete cannot be brought back and removes the lead\'s files. Prefer Lost or Invalid.',
  ],

  access: 'Viewing needs Leads view; adding, editing, moving, importing, merging and deleting need Leads manage. Export needs Reports view. Quote this lead also needs Quotations manage. If your role is "own leads only", you see and work only leads assigned to you. Lead lists, stages, intake and templates need Settings manage.',
}
