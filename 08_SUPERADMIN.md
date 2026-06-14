# 08 — Super Admin Panel

> YOUR control panel as Digital Web Weaver.
> Module control, company config, impersonation, audit logs.

---

## 1. Overview

```
Super Admin = You (Digital Web Weaver)

CAN:
✅ Create new client companies
✅ Enable / disable any module per company
✅ Configure all settings per company
✅ Set pricing, products, accessories
✅ Configure production stages
✅ Set custom fields per form
✅ Login AS any company user (impersonation)
✅ View all audit logs
✅ Manage subscription plans
✅ Backup / restore data

CANNOT:
❌ Edit client financial records directly
   (must impersonate first — logged)
```

### Two Separate Portals
```
Client Portal:  https://signature.panelosapp.com
Super Admin:    https://admin.panelosapp.com

Completely separate login, separate subdomain
Super Admin token NEVER works on client portal
```

---

## 2. Super Admin Login Security

```
URL: https://admin.panelosapp.com/login

Security:
→ 2FA (TOTP via Google Authenticator) MANDATORY
→ IP whitelist (office IP + VPN only)
→ Session timeout: 2 hours
→ All actions logged with IP + timestamp
→ Lock after 5 failed attempts
→ Super Admin users created ONLY via DB seeder (never via API)
→ Separate users table: super_admins

NEVER store credentials in:
❌ Git repository
❌ WhatsApp/Telegram
✅ Use password manager + 2FA always
```

---

## 3. Companies List Screen

```
https://admin.panelosapp.com/companies

┌─────────────────────────┬──────────┬────────────┬────────┬─────────────┐
│ Company                 │ Plan     │ Status     │ Users  │ Actions     │
├─────────────────────────┼──────────┼────────────┼────────┼─────────────┤
│ UMA Signature PUF Panel │ Growth   │ ✅ Active  │ 8      │ [Manage]    │
│ signature.panelosapp    │ ₹4,999/m │            │        │ [View As]   │
│ Last active: 2 hrs ago  │          │            │        │ [Settings]  │
└─────────────────────────┴──────────┴────────────┴────────┴─────────────┘
[+ Create Company]
```

---

## 4. Create New Company

```
BASIC INFO:
Company Name*:   [UMA Signature PUF Panel LLP ]
Subdomain*:      [signature                   ] .panelosapp.com → ✅ Available
GSTIN:           [24AAFFU9050M1ZS             ]
Phone*:          [9574799722                  ]
Email*:          [info@signaturepufpanel.com  ]
State*:          [Gujarat ▼                   ] → State Code: 24

SUBSCRIPTION:
Plan*:           [Growth ▼                    ]
Trial Days:      [14                          ]
Start Date:      [01-Jun-2025                 ]

ADMIN USER:
Name*:           [Pratik Patel                ]
Email*:          [pratik@signaturepuf.com     ]
Temp Password*:  [Welcome@2025                ]
Send Welcome:    ☑ YES

DEFAULT MODULES: (all enabled by default)
☑ BOQ  ☑ Orders  ☑ Production  ☑ Inventory
☑ Dispatch  ☑ Accounts  ☑ CRM  ☑ Reports  ☑ Notifications
☑ Settings (always ON)

SEED DEFAULT DATA:
☑ Signature PUF panel types (5 products)
☑ Default accessories master
☑ Default production stages (8 stages)
☑ Default roles
☑ Default number series (SCP, ORD, INV, CH...)
☑ Default T&C and payment terms

[Create Company & Setup]
```

---

## 5. Module Control Per Company

### Module Toggle Screen
```
MODULES — UMA Signature PUF Panel LLP

┌────────────────────────┬─────────┬──────────────┬──────────────────┐
│ Module                 │ Status  │ Last Used    │ Actions          │
├────────────────────────┼─────────┼──────────────┼──────────────────┤
│ ⚙️ Settings            │ 🔒 Core │ Always       │ [Configure]      │
│ 📄 BOQ & Quotation     │ ✅ ON   │ 2 hrs ago    │ [Config][Toggle] │
│ 🛒 Order Management    │ ✅ ON   │ 1 day ago    │ [Config][Toggle] │
│ 🏭 Production          │ ✅ ON   │ 3 hrs ago    │ [Config][Toggle] │
│ 📦 Inventory           │ ✅ ON   │ 5 hrs ago    │ [Config][Toggle] │
│ 🚚 Dispatch            │ ✅ ON   │ 1 day ago    │ [Config][Toggle] │
│ 💰 Accounts & GST      │ ✅ ON   │ 2 days ago   │ [Config][Toggle] │
│ 👥 CRM & Customers     │ ✅ ON   │ 4 hrs ago    │ [Config][Toggle] │
│ 📊 Reports             │ ✅ ON   │ 1 day ago    │ [Config][Toggle] │
│ 🔔 Notifications       │ ✅ ON   │ Active       │ [Config][Toggle] │
└────────────────────────┴─────────┴──────────────┴──────────────────┘
```

### Toggle Confirmation
```
When toggling OFF:
"⚠️ Disable Production Module?
 This will hide Production menu from all users.
 Existing data preserved. Re-enabling restores access.
 Currently: 3 active batches (will become inaccessible)
 [Cancel] [Disable Anyway]"
```

---

## 6. Module-wise Configuration

### BOQ Module Config
```
Quotation Prefix:           [SCP        ]
Default Validity Days:      [10         ]
Allow Price Override:        ● YES  ○ NO
Max Override % below rate:  [15         ]
Override Requires Approval: ○ YES  ● NO
Show Bank Details on PDF:   ● YES  ○ NO
Show BOQ Sheet (Page 3):    ● YES  ○ NO
Authorized Signatory:       [Pratik Patel]
Payment Terms (default):    [50% advance, 50% before dispatch]
Terms & Conditions:         [Full T&C text — multiline]
Exclusions:                 [1) Unloading... 2) Replacement...]
Offer Validity Text:        [10 days]
Wastage Note:               [Panel std cover width 1MTR...]
```

### Production Module Config
```
Min Production Length (mm): [2000       ] ← The doubling rule
Priority Method:            [Delivery Date ▼]

SHIFTS:
Day Shift:    [07:00] → [19:00]
Night Shift:  [19:00] → [07:00]
General:      [09:00] → [18:00]

QUALITY THRESHOLDS:
Max Rejection Rate Alert %: [5.0]
Thickness Tolerance (mm):   [2.0]
Density Tolerance kg/m³:    [2.0]

CHEMICAL DEFAULTS:
Default Polyol Ratio %:     [55]
Default Isocyanate Ratio %: [45]
Min Oven Temp °C:           [35]
Max Oven Temp °C:           [65]
Default Curing Time (min):  [12]
```

### Accounts Module Config
```
Invoice Prefix:       [INV   ]
Proforma Prefix:      [PFI   ]
Credit Note Prefix:   [CN    ]
Financial Year Start: [April ▼]
E-Invoice Required:   ○ YES  ● NO
  Turnover Threshold: [₹5,00,00,000]
TCS Applicable:       ○ YES  ● NO
  TCS Rate %:         [0.100        ]
Block Dispatch if Due:● YES  ○ NO
Round Off Method:     ○ Up  ○ Down  ● Nearest Rupee
Auto Invoice on Dispatch: ○ YES  ● NO
```

### Notification Config — All Events

| Event Code | Event | Default Channel | Default Recipients |
|---|---|---|---|
| order_confirmed | Order Confirmed | WhatsApp | Owner, Production |
| quotation_accepted | Quotation Accepted | WhatsApp+Email | Owner, Sales |
| production_started | Batch Started | WhatsApp | Owner |
| stage_completed | Stage Completed | In-App | Supervisor |
| qc_failed | QC Failed | WhatsApp | Owner, Supervisor |
| batch_completed | Batch Completed | WhatsApp | Owner, Sales |
| dispatch_done | Dispatch Done | WhatsApp+Email | Customer, Owner |
| payment_received | Payment Received | WhatsApp | Owner, Accountant |
| payment_overdue_30 | Overdue 30 Days | WhatsApp | Owner |
| payment_overdue_60 | Overdue 60 Days | WhatsApp | Owner |
| stock_low | Stock Low | WhatsApp | Owner, Store |
| stockout | Stockout | WhatsApp URGENT | Owner |
| chemical_expiring | Expiring 30 Days | WhatsApp | Store Manager |
| cheque_due | Cheque Due | WhatsApp | Accountant |
| machine_breakdown | Breakdown | WhatsApp URGENT | Owner, Supervisor |

### Template Variables Available
```
{company_name}   {order_no}      {customer_name}
{amount}         {due_date}      {balance_due}
{panel_type}     {sqm}           {stage_name}
{dispatch_date}  {vehicle_no}    {quotation_no}
{validity_date}  {batch_no}      {material_name}
{remaining_qty}  {minimum_qty}
```

---

## 7. View As Client (Impersonation)

### What It Is
```
Super Admin logs in as any company user.
Sees exactly what they see.

Use cases:
→ Support client with issue
→ Verify configuration
→ Test new feature
→ Debug production problem
```

### How to Impersonate
```
Step 1: Company detail page → [View As Client]
Step 2: Select user:
        ● Pratik Patel (Owner)
        ○ Rahul Shah (Sales Manager)
        ○ Ramesh Kumar (Floor Worker)
        Reason: [Support call - BOQ issue]
Step 3: [Impersonate]
Step 4: Redirected to signature.panelosapp.com logged in as selected user

ALWAYS visible banner:
┌──────────────────────────────────────────────────┐
│ 🔴 IMPERSONATING: Pratik Patel @ Signature PUF   │
│ [Exit Impersonation]                             │
└──────────────────────────────────────────────────┘
```

### Impersonation Audit
```
Every session logged:
- Super Admin user who impersonated
- Company + user impersonated
- Reason provided
- Start time + End time
- All actions taken during session
- IP address

Client Company Admin CAN see this log:
"Super Admin accessed your account on 10-Jun-2025
 14:30 | Duration: 12 min | Reason: Support call"

This builds trust — client knows when and why accessed.
```

### Impersonation Restrictions
```
DURING IMPERSONATION:
✅ View all screens
✅ Edit settings
✅ Create records (flagged internally)
❌ Cannot delete financial records
❌ Cannot change subscription/billing
❌ Cannot access super admin panel

All actions logged under BOTH users.
```

---

## 8. Audit Logs

### Audit Log Screen
```
FILTERS: Company | Action | User | Date Range

Timestamp    | Company      | User     | Action  | Details
10-Jun 14:32 | Signature    | Pratik P | Created | BOQ SCP-028
10-Jun 14:15 | Signature    | SuperAdmin| Impersonated | As Pratik P
10-Jun 12:45 | Signature    | Rahul S  | Updated | Order ORD-022
10-Jun 11:30 | Signature    | Ramesh K | Stage Done | BATCH-045 St4
```

### What Gets Logged
```
Auth:       Login, Logout, Failed login, Password change
BOQ:        Create, Edit, Delete, Send, Accept, Reject, Revise
Orders:     Create, Edit, Cancel, Status change
Production: Batch create, Stage complete, QC log
Inventory:  Stock in/out, Adjustment
Dispatch:   Create, Mark delivered
Invoice:    Create, Finalize, Send
Payment:    Record, Update, Reverse
Settings:   ANY settings change
Super Admin:All actions + Impersonation

Retention: 7 years (GST audit requirement)
Immutable: Cannot be deleted by anyone
```

---

## 9. System Health Dashboard

```
SYSTEM HEALTH

┌──────────┬──────────┬──────────┬──────────┐
│ Companies│ Users    │ Orders   │ Storage  │
│ 1 Active │ 8 Active │ 45 Total │ 245 MB   │
└──────────┴──────────┴──────────┴──────────┘

SERVER STATUS:
✅ API Server:   Online | Response: 45ms
✅ Database:     Online | Queries: 234/min
✅ Redis:        Online | Hit rate: 89%
✅ Queue Worker: Online | Pending: 2 jobs
✅ WhatsApp API: Online | Last sent: 5 min ago

RECENT ERRORS:
⚠️ 10-Jun 12:45 | PDF generation timeout [View]

QUEUE:
→ PDF Generation: SCP-2025-028 (queued 30s ago)
→ WhatsApp: Order confirmation (queued 1 min ago)

[Create Backup Now] [Download Latest Backup]
```

---

## 10. Super Admin Navigation

```
admin.panelosapp.com

SIDEBAR:
├── 🏠 Dashboard          ← System overview
├── 🏢 Companies          ← All clients
│   ├── List
│   ├── Create New
│   └── [Company Name]
│       ├── Overview
│       ├── Modules
│       ├── Settings
│       ├── Users
│       └── Audit Logs
├── 🔑 Access
│   ├── Super Admin Users
│   └── Impersonation Log
├── 🔧 System
│   ├── Health Monitor
│   ├── Queue Monitor
│   ├── Error Logs
│   └── Database Backups
└── 📊 Analytics
    ├── Usage Stats
    └── Revenue Overview
```

