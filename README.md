# PanelOS — PUF Panel Manufacturing ERP

> **"Aapki factory ka brain"**
> India's first dedicated ERP for PUF / PIR / Sandwich Panel Manufacturers.
> Single-tenant first. SaaS-ready architecture.

---

## Quick Navigation

| Document | Description |
|---|---|
| [01_BUSINESS_LOGIC.md](docs/01_BUSINESS_LOGIC.md) | All business rules, calculations, edge cases |
| [02_DATABASE_SCHEMA.md](docs/02_DATABASE_SCHEMA.md) | Every table, column, relationship |
| [03_API_ENDPOINTS.md](docs/03_API_ENDPOINTS.md) | All REST API routes |
| [04_BOQ_MODULE.md](docs/04_BOQ_MODULE.md) | BOQ screen — complete spec |
| [05_PRODUCTION_MODULE.md](docs/05_PRODUCTION_MODULE.md) | Production, cutting, quality |
| [06_INVENTORY_MODULE.md](docs/06_INVENTORY_MODULE.md) | Coils, chemicals, accessories |
| [07_ACCOUNTS_MODULE.md](docs/07_ACCOUNTS_MODULE.md) | Invoice, GST, payments |
| [08_SUPERADMIN.md](docs/08_SUPERADMIN.md) | Super admin panel |
| [09_FORM_BUILDER.md](docs/09_FORM_BUILDER.md) | Dynamic fields system |
| [10_UI_DESIGN_SYSTEM.md](docs/10_UI_DESIGN_SYSTEM.md) | Colors, components, layouts |
| [11_SETUP_GUIDE.md](docs/11_SETUP_GUIDE.md) | Installation & deployment |

---

## Project Overview

**PanelOS** is a manufacturing ERP purpose-built for PUF, PIR, and Sandwich Panel manufacturers.

### Reference Client
```
Company:  UMA Signature PUF Panel LLP
Location: Survey No 158/1, Dhanora, Manglej-Nareshwar Road,
          Karjan, Vadodara - 391210, Gujarat
GSTIN:    24AAFFU9050M1ZS
Website:  https://signaturepufpanel.com
Contact:  Pratik Patel / 9574799722
Bank:     HDFC Bank | A/c 59200000008899 | IFSC HDFC0003001
Capacity: 20 Lakh SQM/year
Products: 5 panel types
```

---

## Tech Stack

| Layer | Technology | Version |
|---|---|---|
| Backend | Laravel | 11.x |
| Frontend | Vue.js | 3.x (Composition API) |
| UI Library | PrimeVue | 4.x |
| State | Pinia | 2.x |
| Auth | Laravel Sanctum | Built-in |
| Multi-tenancy | stancl/tenancy | 3.x |
| Database | MySQL | 8.x |
| Cache | Redis | 7.x |
| Queue | Laravel Queue | Built-in |
| PDF | Laravel DomPDF | Latest |
| Mobile (Phase 3) | React Native | — |

---

## System Architecture

```
┌─────────────────────────────────────────────┐
│              PANELOS SYSTEM                  │
├──────────────────┬──────────────────────────┤
│  SUPER ADMIN     │  CLIENT (Signature PUF)  │
│  (DWW)          │                          │
│                  │  Owner / Admin           │
│  Enable/Disable  │  Sales Manager           │
│  Modules         │  Production Supervisor   │
│  Configure all   │  Floor Worker            │
│  Custom fields   │  Accountant              │
│  View As Client  │  Dispatch Executive      │
│  Audit logs      │  Dealer / Installer      │
└──────────────────┴──────────────────────────┘
```

---

## Modules

| # | Module | Configurable |
|---|---|---|
| 1 | BOQ & Quotation | Full |
| 2 | Production | Full — stages, machines, rules |
| 3 | Inventory | Full |
| 4 | Dispatch & Logistics | Full |
| 5 | Accounts & GST | Full |
| 6 | CRM & Customers | Full |
| 7 | Reports & Analytics | Per report toggle |
| 8 | Notifications | Per event, per channel |
| 9 | Settings | Always ON |
| 10 | Super Admin | Super Admin only |

---

## HSN Code Reference

| Item | HSN | GST |
|---|---|---|
| PUF/PIR Panel (Roof, Wall, Ceiling) | 39259010 | 18% |
| Cold Room Panel | 39259010 | 18% |
| GI Accessories / Flashing | 73089090 | 18% |
| Aluminium Accessories | 76169990 | 18% |
| Installation Service | 994568 | 18% |
| Transportation | 996511 | 5%/12% |

---

## Key Business Rules

1. Panel width = always 1000mm (read-only)
2. SQM = (Length ÷ 1000) × (Width ÷ 1000) × Nos
3. Panel < 2000mm → produced at doubled length
4. Surface (Ribbed/Plain) = per panel row, NOT header
5. Roof panels → Top: Ribbed (auto), Bottom: Plain
6. Wall/Ceiling/Cold Room → Top: Plain, Bottom: Plain
7. Payment = 50% advance, 50% min 2 days before dispatch
8. Offer validity = 10 days default
9. Delivery = 14 days standard, 21 days non-standard
10. GST = Intra-state: CGST+SGST | Inter-state: IGST

---

## Products (Signature PUF)

| Product | Category | Thickness | Special |
|---|---|---|---|
| Signature Cool Roof | Roof | 30-120mm | 48 kg/m³, 5-rib, CFC-free |
| Signature Top Roof | Roof | 30-120mm | PVC-coated interior |
| Signature Tuff Wall | Wall | 30-100mm | Micro-ribbed, visible fix |
| Signature Secret Fix | Wall | 50,100mm | Hidden fixing |
| Signature Cold Panel | Cold Room | 80-180mm | Labyrinth joint |

---

## Phase Plan

### Phase 1 — MVP (16 Weeks)
BOQ + Orders + Production + Inventory + Dispatch + Accounts + Dashboard

### Phase 2 — Enhancement (Month 5-7)
Mobile app + WhatsApp + Dealer portal + Customer portal

### Phase 3 — SaaS (Month 8-10)
Signup + Billing + Multi-tenant self-service

---

## Folder Structure

```
panelos/
├── README.md
├── docs/
│   ├── 01_BUSINESS_LOGIC.md
│   ├── 02_DATABASE_SCHEMA.md
│   ├── 03_API_ENDPOINTS.md
│   ├── 04_BOQ_MODULE.md
│   ├── 05_PRODUCTION_MODULE.md
│   ├── 06_INVENTORY_MODULE.md
│   ├── 07_ACCOUNTS_MODULE.md
│   ├── 08_SUPERADMIN.md
│   ├── 09_FORM_BUILDER.md
│   ├── 10_UI_DESIGN_SYSTEM.md
│   └── 11_SETUP_GUIDE.md
├── backend/   (Laravel 11)
└── frontend/  (Vue 3 + PrimeVue)
```

*Built by: Digital Web Weaver — digitalwebweaver.com*
