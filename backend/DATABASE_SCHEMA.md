# PanelOS Database Schema

## Overview

Complete database schema documentation for PanelOS. Uses MySQL 8.0 with UTF8MB4 collation for international support.

**Key Principles**:
- Every table has `company_id` for multi-tenancy (except `companies`)
- Soft deletes on critical entities
- Timestamps on all tables
- Foreign key cascades for data integrity
- Indexes for query performance

---

## Entity Relationship Diagram

```
┌──────────────┐
│  Companies   │◄─────────────────┐
│              │                  │
│ • id (PK)    │                  │
│ • subdomain  │                  │
│ • settings   │                  │
└──────┬───────┘                  │
       │                          │
       ├─────────────────────────┬┴──────────────┬────────────────┐
       │                         │               │                │
       ▼                         ▼               ▼                ▼
┌──────────────┐        ┌──────────────┐  ┌──────────────┐  ┌─────────────┐
│    Users     │        │    Roles     │  │ Customers    │  │ Quotations  │
│              │        │              │  │              │  │             │
│ • id (PK)    │        │ • id (PK)    │  │ • id (PK)    │  │ • id (PK)   │
│ • company_id │        │ • company_id │  │ • company_id │  │ • company_id│
│ • role_id    │        │ • name       │  │ • name       │  │ • status    │
│ • email      │        │ • permissions   │ • email      │  │ • total     │
│ • password   │        │              │  │              │  │             │
│ • tokens     │        │              │  │              │  │             │
└──────────────┘        └──────────────┘  └──────────────┘  └──────┬──────┘
                                                                   │
                                                                   ▼
                                                         ┌──────────────────┐
                                                         │  Quotation Items │
                                                         │                  │
                                                         │ • id (PK)        │
                                                         │ • quotation_id   │
                                                         │ • panel_type_id  │
                                                         │ • quantity       │
                                                         │ • unit_price     │
                                                         │ • amount         │
                                                         └──────────────────┘
```

---

## Tables

### 1. companies
Root tenant entity. All other entities reference this.

```sql
CREATE TABLE companies (
  id bigint PRIMARY KEY AUTO_INCREMENT,
  name varchar(255) NOT NULL,
  subdomain varchar(100) UNIQUE NOT NULL,
  logo varchar(255) NULL,
  email varchar(255) NULL,
  phone varchar(20) NULL,
  
  -- Address
  address_line1 varchar(255) NULL,
  city varchar(100) NULL,
  state varchar(100) NULL,
  state_code varchar(5) NULL,
  pincode varchar(10) NULL,
  
  -- Taxation
  gstin varchar(20) UNIQUE NULL,
  pan varchar(20) UNIQUE NULL,
  
  -- Banking
  bank_name varchar(255) NULL,
  bank_account_no varchar(50) NULL,
  bank_ifsc varchar(20) NULL,
  bank_branch varchar(255) NULL,
  authorized_signatory varchar(255) NULL,
  signatory_phone varchar(20) NULL,
  
  -- Branding
  primary_color varchar(7) DEFAULT '#1a237e',
  secondary_color varchar(7) DEFAULT '#f57f17',
  
  -- Document Prefixes
  quotation_prefix varchar(10) DEFAULT 'SCP',
  invoice_prefix varchar(10) DEFAULT 'INV',
  order_prefix varchar(10) DEFAULT 'ORD',
  challan_prefix varchar(10) DEFAULT 'CH',
  
  -- Configuration
  financial_year_start tinyint DEFAULT 4,
  e_invoice_applicable boolean DEFAULT false,
  tcs_applicable boolean DEFAULT false,
  
  -- Subscription
  subscription_plan enum('starter','growth','pro','enterprise') DEFAULT 'growth',
  subscription_status enum('active','trial','expired') DEFAULT 'trial',
  is_active boolean DEFAULT true,
  
  -- JSON Settings
  settings json NULL,
  
  created_at timestamp,
  updated_at timestamp,
  deleted_at timestamp NULL
);

KEY idx_subdomain (subdomain);
KEY idx_gstin (gstin);
KEY idx_is_active (is_active);
```

**Fields**:
- `id` - Unique company identifier
- `subdomain` - Unique identifier for multi-tenancy routing
- `gstin` - GST Identification Number (India)
- `pan` - Permanent Account Number (India)
- `settings` - JSON configuration (colors, prefixes, tax settings)
- `subscription_plan` - Billing tier (starter → enterprise)

---

### 2. users
User accounts with authentication.

```sql
CREATE TABLE users (
  id bigint PRIMARY KEY AUTO_INCREMENT,
  company_id bigint NOT NULL,
  role_id bigint NOT NULL,
  
  name varchar(255) NOT NULL,
  email varchar(255) UNIQUE NOT NULL,
  phone varchar(20) NULL,
  whatsapp_no varchar(20) NULL,
  
  password varchar(255) NOT NULL,
  email_verified_at timestamp NULL,
  
  is_super_admin boolean DEFAULT false,
  is_company_admin boolean DEFAULT false,
  is_active boolean DEFAULT true,
  
  last_login_at timestamp NULL,
  
  created_at timestamp,
  updated_at timestamp,
  deleted_at timestamp NULL,
  
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL
);

UNIQUE KEY uk_email (email);
KEY idx_company_id (company_id);
KEY idx_role_id (role_id);
KEY idx_is_active (is_active);
```

**Fields**:
- `password` - Bcrypt hashed
- `is_super_admin` - Full system access
- `is_company_admin` - Company-level admin
- `last_login_at` - Tracking for security

**Relationships**:
- `company_id` → companies.id
- `role_id` → roles.id
- `personal_access_tokens` (Sanctum auth tokens)

---

### 3. roles
Authorization roles with permission sets.

```sql
CREATE TABLE roles (
  id bigint PRIMARY KEY AUTO_INCREMENT,
  company_id bigint NOT NULL,
  
  name varchar(255) NOT NULL,
  guard_name varchar(255) DEFAULT 'web',
  description text NULL,
  
  is_system_role boolean DEFAULT false,
  permissions json NOT NULL DEFAULT '[]',
  
  created_at timestamp,
  updated_at timestamp,
  
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  UNIQUE KEY uk_company_role (company_id, name)
);

KEY idx_company_id (company_id);
KEY idx_is_system_role (is_system_role);
```

**Default Roles**:
```
1. Super Admin
   permissions: ['*']

2. Company Admin
   permissions: ['companies.manage', 'users.manage', 'roles.manage']

3. Sales Manager
   permissions: ['quotations.create', 'quotations.edit', 'orders.view']

4. Production Manager
   permissions: ['batches.view', 'batches.update', 'production.complete']

5. Accounts
   permissions: ['invoices.view', 'invoices.create', 'payments.manage']

6. Viewer
   permissions: ['*.view']
```

---

### 4. customers
Customer/client management.

```sql
CREATE TABLE customers (
  id bigint PRIMARY KEY AUTO_INCREMENT,
  company_id bigint NOT NULL,
  
  name varchar(255) NOT NULL,
  email varchar(255) NULL,
  phone varchar(20) NULL,
  whatsapp_no varchar(20) NULL,
  
  -- Address
  address_line1 varchar(255) NULL,
  city varchar(100) NULL,
  state varchar(100) NULL,
  pincode varchar(10) NULL,
  
  -- Taxation
  gstin varchar(20) NULL,
  pan varchar(20) NULL,
  
  -- Credit Terms
  credit_limit decimal(12, 2) DEFAULT 0.00,
  credit_days smallint DEFAULT 0,
  outstanding_amount decimal(12, 2) DEFAULT 0.00,
  
  is_active boolean DEFAULT true,
  
  created_at timestamp,
  updated_at timestamp,
  deleted_at timestamp NULL,
  
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

KEY idx_company_id (company_id);
KEY idx_email (email);
KEY idx_gstin (gstin);
KEY idx_is_active (is_active);
```

---

### 5. panel_types
Product definitions (coming Phase 2).

```sql
CREATE TABLE panel_types (
  id bigint PRIMARY KEY AUTO_INCREMENT,
  company_id bigint NOT NULL,
  
  name varchar(255) NOT NULL,
  code varchar(50) UNIQUE NOT NULL,
  description text NULL,
  
  -- Physical Properties
  thickness decimal(5, 2) NOT NULL,
  width decimal(8, 2) NOT NULL,
  thermal_resistance decimal(8, 2) NOT NULL,
  
  -- Pricing
  base_price decimal(10, 2) NOT NULL,
  
  is_active boolean DEFAULT true,
  
  created_at timestamp,
  updated_at timestamp,
  deleted_at timestamp NULL,
  
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

KEY idx_company_id (company_id);
KEY idx_code (code);
```

---

### 6. quotations
Bill of Quantities (BOQ) - coming Phase 2.

```sql
CREATE TABLE quotations (
  id bigint PRIMARY KEY AUTO_INCREMENT,
  company_id bigint NOT NULL,
  
  quotation_no varchar(50) UNIQUE NOT NULL,
  customer_id bigint NOT NULL,
  
  -- Status Workflow
  status enum('draft','sent','accepted','rejected','expired') DEFAULT 'draft',
  
  -- Amounts
  subtotal decimal(12, 2) NOT NULL,
  tax_amount decimal(12, 2) DEFAULT 0.00,
  total_amount decimal(12, 2) NOT NULL,
  
  -- Dates
  quoted_on date NOT NULL,
  valid_until date NULL,
  sent_at timestamp NULL,
  accepted_at timestamp NULL,
  
  notes text NULL,
  
  created_at timestamp,
  updated_at timestamp,
  deleted_at timestamp NULL,
  
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT
);

UNIQUE KEY uk_quotation_no (quotation_no);
KEY idx_company_id (company_id);
KEY idx_customer_id (customer_id);
KEY idx_status (status);
KEY idx_created_at (created_at);
```

---

### 7. quotation_items
Line items in quotations.

```sql
CREATE TABLE quotation_items (
  id bigint PRIMARY KEY AUTO_INCREMENT,
  quotation_id bigint NOT NULL,
  panel_type_id bigint NOT NULL,
  
  quantity decimal(10, 2) NOT NULL,
  unit_price decimal(10, 2) NOT NULL,
  amount decimal(12, 2) NOT NULL,
  
  created_at timestamp,
  updated_at timestamp,
  
  FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
  FOREIGN KEY (panel_type_id) REFERENCES panel_types(id) ON DELETE RESTRICT
);

KEY idx_quotation_id (quotation_id);
KEY idx_panel_type_id (panel_type_id);
```

---

### 8. orders
Orders generated from quotations (Phase 3).

```sql
CREATE TABLE orders (
  id bigint PRIMARY KEY AUTO_INCREMENT,
  company_id bigint NOT NULL,
  
  order_no varchar(50) UNIQUE NOT NULL,
  quotation_id bigint NOT NULL,
  customer_id bigint NOT NULL,
  
  status enum('pending','in_production','ready','dispatched','delivered','cancelled') DEFAULT 'pending',
  
  total_amount decimal(12, 2) NOT NULL,
  
  -- Dates
  ordered_on date NOT NULL,
  delivery_date date NULL,
  
  created_at timestamp,
  updated_at timestamp,
  deleted_at timestamp NULL,
  
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE RESTRICT,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT
);

UNIQUE KEY uk_order_no (order_no);
KEY idx_company_id (company_id);
KEY idx_status (status);
```

---

### 9. production_batches
Production batch tracking (Phase 3).

```sql
CREATE TABLE production_batches (
  id bigint PRIMARY KEY AUTO_INCREMENT,
  company_id bigint NOT NULL,
  order_id bigint NOT NULL,
  
  batch_no varchar(50) UNIQUE NOT NULL,
  status enum('pending','in_progress','qc_pending','completed','on_hold') DEFAULT 'pending',
  
  created_at timestamp,
  updated_at timestamp,
  deleted_at timestamp NULL,
  
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT
);

UNIQUE KEY uk_batch_no (batch_no);
KEY idx_company_id (company_id);
KEY idx_order_id (order_id);
KEY idx_status (status);
```

---

### 10. invoices
Tax invoices (Phase 5).

```sql
CREATE TABLE invoices (
  id bigint PRIMARY KEY AUTO_INCREMENT,
  company_id bigint NOT NULL,
  
  invoice_no varchar(50) UNIQUE NOT NULL,
  order_id bigint NOT NULL,
  customer_id bigint NOT NULL,
  
  -- Amounts
  subtotal decimal(12, 2) NOT NULL,
  tax_amount decimal(12, 2) NOT NULL,
  total_amount decimal(12, 2) NOT NULL,
  
  -- Dates
  invoice_date date NOT NULL,
  due_date date NULL,
  paid_at timestamp NULL,
  
  status enum('draft','issued','paid','cancelled','overdue') DEFAULT 'draft',
  
  notes text NULL,
  
  created_at timestamp,
  updated_at timestamp,
  deleted_at timestamp NULL,
  
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT
);

UNIQUE KEY uk_invoice_no (invoice_no);
KEY idx_company_id (company_id);
KEY idx_status (status);
KEY idx_invoice_date (invoice_date);
```

---

### 11. coil_stock
Raw material inventory (Phase 4).

```sql
CREATE TABLE coil_stock (
  id bigint PRIMARY KEY AUTO_INCREMENT,
  company_id bigint NOT NULL,
  
  coil_id varchar(50) UNIQUE NOT NULL,
  description varchar(255) NOT NULL,
  
  quantity_available decimal(10, 2) NOT NULL,
  quantity_reserved decimal(10, 2) DEFAULT 0.00,
  quantity_damaged decimal(10, 2) DEFAULT 0.00,
  
  reorder_level decimal(10, 2) DEFAULT 0.00,
  unit_cost decimal(10, 2) NOT NULL,
  
  last_received_at timestamp NULL,
  
  created_at timestamp,
  updated_at timestamp,
  deleted_at timestamp NULL,
  
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

UNIQUE KEY uk_coil_id (coil_id);
KEY idx_company_id (company_id);
KEY idx_quantity_available (quantity_available);
```

---

### 12. dispatches
Dispatch/challan tracking (Phase 4).

```sql
CREATE TABLE dispatches (
  id bigint PRIMARY KEY AUTO_INCREMENT,
  company_id bigint NOT NULL,
  
  challan_no varchar(50) UNIQUE NOT NULL,
  order_id bigint NOT NULL,
  customer_id bigint NOT NULL,
  
  dispatch_date date NOT NULL,
  expected_delivery_at date NULL,
  delivered_at timestamp NULL,
  
  status enum('pending','shipped','in_transit','delivered','returned') DEFAULT 'pending',
  
  created_at timestamp,
  updated_at timestamp,
  deleted_at timestamp NULL,
  
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT
);

UNIQUE KEY uk_challan_no (challan_no);
KEY idx_company_id (company_id);
KEY idx_status (status);
```

---

### 13. payments
Payment recording (Phase 5).

```sql
CREATE TABLE payments (
  id bigint PRIMARY KEY AUTO_INCREMENT,
  company_id bigint NOT NULL,
  
  invoice_id bigint NOT NULL,
  
  amount decimal(12, 2) NOT NULL,
  payment_method enum('cash','cheque','bank_transfer','card','other') NOT NULL,
  
  payment_date date NOT NULL,
  reference_no varchar(100) NULL,
  
  -- Cheque specific
  cheque_no varchar(50) NULL,
  cheque_date date NULL,
  cheque_status enum('pending','cleared','bounced','cancelled') NULL,
  
  notes text NULL,
  
  created_at timestamp,
  updated_at timestamp,
  
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT
);

KEY idx_company_id (company_id);
KEY idx_invoice_id (invoice_id);
KEY idx_payment_date (payment_date);
```

---

### Sanctum Tables (Laravel)

#### personal_access_tokens
API token authentication (auto-created by Sanctum).

```sql
CREATE TABLE personal_access_tokens (
  id bigint PRIMARY KEY AUTO_INCREMENT,
  tokenable_type varchar(255) NOT NULL,
  tokenable_id bigint NOT NULL,
  name varchar(255) NOT NULL,
  token varchar(64) UNIQUE NOT NULL,
  abilities longtext NULL,
  last_used_at timestamp NULL,
  expires_at timestamp NULL,
  created_at timestamp,
  updated_at timestamp
);

KEY idx_tokenable (tokenable_type, tokenable_id);
KEY idx_token (token);
```

---

## Indexes Summary

### Performance Indexes

```sql
-- Multi-tenancy
ALTER TABLE users ADD INDEX idx_company_id (company_id);
ALTER TABLE roles ADD INDEX idx_company_id (company_id);
ALTER TABLE quotations ADD INDEX idx_company_id (company_id);
ALTER TABLE orders ADD INDEX idx_company_id (company_id);

-- Status filtering
ALTER TABLE quotations ADD INDEX idx_status (status);
ALTER TABLE orders ADD INDEX idx_status (status);
ALTER TABLE invoices ADD INDEX idx_status (status);

-- Date filtering
ALTER TABLE quotations ADD INDEX idx_created_at (created_at);
ALTER TABLE invoices ADD INDEX idx_invoice_date (invoice_date);

-- Foreign keys
ALTER TABLE users ADD INDEX idx_role_id (role_id);
ALTER TABLE quotations ADD INDEX idx_customer_id (customer_id);
ALTER TABLE orders ADD INDEX idx_quotation_id (quotation_id);
```

---

## Relationships

```
Company (1) ──────┬──> (N) Users
                  ├──> (N) Roles
                  ├──> (N) Customers
                  ├──> (N) Quotations
                  ├──> (N) Orders
                  ├──> (N) Production Batches
                  ├──> (N) Invoices
                  ├──> (N) Payments
                  └──> (N) Dispatches

Quotation (1) ────> (N) Quotation Items
              ──> (1) Customer
              ──> (1) Order

Order (1) ────┬──> (N) Order Items
              ├──> (1) Quotation
              ├──> (N) Production Batches
              ├──> (N) Invoices
              └──> (1) Dispatch

Invoice (1) ──┬──> (N) Payments
              ├──> (1) Order
              └──> (1) Customer

User (1) ────> (1) Role
         ────> (1) Company
         ────> (N) Personal Access Tokens (Sanctum)
```

---

## Query Examples

### Get User with Company and Role
```sql
SELECT u.*, c.name as company_name, r.name as role_name
FROM users u
JOIN companies c ON u.company_id = c.id
JOIN roles r ON u.role_id = r.id
WHERE u.id = 1;
```

### Get Quotation with Items
```sql
SELECT 
  q.*, 
  qi.*, 
  pt.name as panel_name
FROM quotations q
LEFT JOIN quotation_items qi ON q.id = qi.quotation_id
LEFT JOIN panel_types pt ON qi.panel_type_id = pt.id
WHERE q.id = 1;
```

### Low Stock Alerts
```sql
SELECT * FROM coil_stock
WHERE company_id = 1
AND quantity_available < reorder_level
AND deleted_at IS NULL;
```

### Outstanding Invoices
```sql
SELECT * FROM invoices
WHERE company_id = 1
AND status IN ('issued', 'overdue')
AND deleted_at IS NULL
ORDER BY due_date ASC;
```

---

## Migrations

All tables are created via Laravel migrations:
```
database/migrations/
├── 2026_05_17_000001_create_companies_table.php
├── 2026_05_17_000002_create_roles_table.php
├── 2026_05_17_000003_create_users_table.php
├── 2026_05_17_000004_create_customers_table.php
├── 2026_05_17_000005_create_panel_types_table.php
├── 2026_05_17_000006_create_quotations_table.php
├── 2026_05_17_000007_create_quotation_items_table.php
├── 2026_05_17_000008_create_orders_table.php
├── 2026_05_17_000009_create_production_batches_table.php
├── 2026_05_17_000010_create_invoices_table.php
├── 2026_05_17_000011_create_coil_stock_table.php
├── 2026_05_17_000012_create_dispatches_table.php
├── 2026_05_17_000013_create_payments_table.php
└── 2026_05_17_000014_create_personal_access_tokens_table.php
```

---

## Statistics

| Metric | Value |
|--------|-------|
| Total Tables | 14 |
| Phase 1 Complete | 4 |
| Phase 2-3 Planned | 5 |
| Phase 4-5 Planned | 5 |
| Total Columns | 150+ |
| Foreign Keys | 20+ |
| Indexes | 30+ |

---

**Last Updated**: 2026-05-17  
**Database**: MySQL 8.0  
**Collation**: utf8mb4_unicode_ci  
**Version**: 1.0
