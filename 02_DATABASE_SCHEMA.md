# 02 — Database Schema

> Complete database design. MySQL 8.x. Every table, every column.
> SaaS-ready: every table has company_id.

---

## Core Design Rules

1. Every table has `company_id` — foundation for multi-tenancy
2. All models use `TenantScoped` trait — auto WHERE company_id
3. Soft deletes on all critical tables (deleted_at)
4. Audit columns — created_by, updated_by on key tables
5. JSON columns for flexible config

---

## KEY TABLES QUICK REFERENCE

### companies
```sql
CREATE TABLE companies (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                  VARCHAR(255) NOT NULL,
  subdomain             VARCHAR(100) NOT NULL UNIQUE,
  logo                  VARCHAR(500) NULL,
  gstin                 VARCHAR(20) NULL,
  pan                   VARCHAR(20) NULL,
  address_line1         VARCHAR(255) NULL,
  city                  VARCHAR(100) NULL,
  state                 VARCHAR(100) NULL,
  state_code            VARCHAR(5) NULL,
  pincode               VARCHAR(10) NULL,
  phone                 VARCHAR(20) NULL,
  email                 VARCHAR(255) NULL,
  bank_name             VARCHAR(255) NULL,
  bank_account_no       VARCHAR(50) NULL,
  bank_ifsc             VARCHAR(20) NULL,
  bank_branch           VARCHAR(255) NULL,
  authorized_signatory  VARCHAR(255) NULL,
  signatory_phone       VARCHAR(20) NULL,
  primary_color         VARCHAR(20) DEFAULT '#1a237e',
  secondary_color       VARCHAR(20) DEFAULT '#f57f17',
  quotation_prefix      VARCHAR(20) DEFAULT 'SCP',
  invoice_prefix        VARCHAR(20) DEFAULT 'INV',
  order_prefix          VARCHAR(20) DEFAULT 'ORD',
  challan_prefix        VARCHAR(20) DEFAULT 'CH',
  financial_year_start  TINYINT DEFAULT 4,
  e_invoice_applicable  BOOLEAN DEFAULT FALSE,
  tcs_applicable        BOOLEAN DEFAULT FALSE,
  subscription_plan     ENUM('starter','growth','pro','enterprise') DEFAULT 'growth',
  subscription_status   ENUM('active','trial','expired') DEFAULT 'trial',
  is_active             BOOLEAN DEFAULT TRUE,
  settings              JSON NULL,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL
);
```

### users
```sql
CREATE TABLE users (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id        BIGINT UNSIGNED NOT NULL,
  name              VARCHAR(255) NOT NULL,
  email             VARCHAR(255) NOT NULL,
  phone             VARCHAR(20) NULL,
  whatsapp_no       VARCHAR(20) NULL,
  password          VARCHAR(255) NOT NULL,
  role_id           BIGINT UNSIGNED NULL,
  is_super_admin    BOOLEAN DEFAULT FALSE,
  is_company_admin  BOOLEAN DEFAULT FALSE,
  is_active         BOOLEAN DEFAULT TRUE,
  last_login_at     TIMESTAMP NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at        TIMESTAMP NULL,
  UNIQUE KEY uk_email_company (email, company_id)
);
```

### panel_types
```sql
CREATE TABLE panel_types (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id                 BIGINT UNSIGNED NOT NULL,
  name                       VARCHAR(255) NOT NULL,
  code                       VARCHAR(50) NOT NULL,
  category                   ENUM('roof','wall','cold_room','ceiling','partition','other') NOT NULL,
  core_material              ENUM('puf','pir','rockwool','eps','glasswool') DEFAULT 'puf',
  hsn_code                   VARCHAR(20) NOT NULL,
  gst_rate                   DECIMAL(5,2) DEFAULT 18.00,
  standard_width_mm          INT DEFAULT 1000,
  allow_custom_width         BOOLEAN DEFAULT FALSE,
  available_thicknesses      JSON NOT NULL,
  available_densities_puf    JSON NULL,
  available_densities_pir    JSON NULL,
  default_density_type       ENUM('puf','pir') DEFAULT 'puf',
  default_density            DECIMAL(5,2) DEFAULT 40.00,
  top_skin_materials         JSON NULL,
  bottom_skin_materials      JSON NULL,
  top_skin_thicknesses       JSON NULL,
  bottom_skin_thicknesses    JSON NULL,
  default_top_surface        ENUM('ribbed','plain') DEFAULT 'plain',
  default_bottom_surface     ENUM('ribbed','plain') DEFAULT 'plain',
  min_production_length_mm   INT DEFAULT 2000,
  delivery_days_standard     INT DEFAULT 14,
  delivery_days_nonstandard  INT DEFAULT 21,
  warranty_months            INT DEFAULT 12,
  product_image              VARCHAR(500) NULL,
  description                TEXT NULL,
  is_active                  BOOLEAN DEFAULT TRUE,
  sort_order                 INT DEFAULT 0,
  created_at                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at                 TIMESTAMP NULL,
  UNIQUE KEY uk_code_company (code, company_id)
);
```

### quotations
```sql
CREATE TABLE quotations (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id            BIGINT UNSIGNED NOT NULL,
  quotation_no          VARCHAR(50) NOT NULL,
  version               INT DEFAULT 1,
  parent_quotation_id   BIGINT UNSIGNED NULL,
  enquiry_id            BIGINT UNSIGNED NULL,
  customer_id           BIGINT UNSIGNED NOT NULL,
  project_name          VARCHAR(255) NULL,
  project_location      TEXT NULL,
  quotation_date        DATE NOT NULL,
  validity_days         INT DEFAULT 10,
  valid_until           DATE NULL,
  quality_grade         ENUM('high','medium','standard') DEFAULT 'high',
  payment_terms         TEXT NULL,
  terms_conditions      TEXT NULL,
  total_sqm             DECIMAL(10,2) DEFAULT 0.00,
  panel_subtotal        DECIMAL(12,2) DEFAULT 0.00,
  accessory_subtotal    DECIMAL(12,2) DEFAULT 0.00,
  subtotal              DECIMAL(12,2) DEFAULT 0.00,
  discount_pct          DECIMAL(5,2) DEFAULT 0.00,
  discount_amount       DECIMAL(12,2) DEFAULT 0.00,
  taxable_amount        DECIMAL(12,2) DEFAULT 0.00,
  cgst_amount           DECIMAL(12,2) DEFAULT 0.00,
  sgst_amount           DECIMAL(12,2) DEFAULT 0.00,
  igst_amount           DECIMAL(12,2) DEFAULT 0.00,
  total_gst             DECIMAL(12,2) DEFAULT 0.00,
  transportation_type   ENUM('fixed','extra_actual','included') DEFAULT 'extra_actual',
  transportation_amount DECIMAL(10,2) DEFAULT 0.00,
  grand_total           DECIMAL(12,2) DEFAULT 0.00,
  advance_amount        DECIMAL(12,2) DEFAULT 0.00,
  balance_amount        DECIMAL(12,2) DEFAULT 0.00,
  is_intrastate         BOOLEAN DEFAULT TRUE,
  notes                 TEXT NULL,
  internal_notes        TEXT NULL,
  status                ENUM('draft','sent','accepted','rejected','revised','expired','cancelled') DEFAULT 'draft',
  sent_at               TIMESTAMP NULL,
  accepted_at           TIMESTAMP NULL,
  custom_fields         JSON NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL,
  UNIQUE KEY uk_quotation_no_company (quotation_no, company_id)
);
```

### quotation_panel_items
```sql
CREATE TABLE quotation_panel_items (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id            BIGINT UNSIGNED NOT NULL,
  quotation_id          BIGINT UNSIGNED NOT NULL,
  panel_type_id         BIGINT UNSIGNED NOT NULL,
  thickness_mm          INT NOT NULL,
  density_type          ENUM('puf','pir') NOT NULL,
  density_kg_m3         DECIMAL(5,2) NOT NULL,
  top_skin_material     VARCHAR(50) NOT NULL,
  top_skin_thickness_mm DECIMAL(4,2) NOT NULL,
  top_skin_color        VARCHAR(100) NULL,
  top_surface           ENUM('ribbed','plain') NOT NULL,
  bottom_skin_material  VARCHAR(50) NOT NULL,
  bottom_skin_thickness_mm DECIMAL(4,2) NOT NULL,
  bottom_skin_color     VARCHAR(100) NULL,
  bottom_surface        ENUM('ribbed','plain') NOT NULL,
  guard_film            BOOLEAN DEFAULT FALSE,
  cello_tap             BOOLEAN DEFAULT FALSE,
  length_mm             DECIMAL(10,2) NOT NULL,
  width_mm              DECIMAL(10,2) DEFAULT 1000,
  nos                   DECIMAL(10,2) NOT NULL,
  sqm                   DECIMAL(10,4) NOT NULL,
  final_rate            DECIMAL(10,2) NOT NULL,
  amount                DECIMAL(12,2) NOT NULL,
  hsn_code              VARCHAR(20) NOT NULL,
  gst_rate              DECIMAL(5,2) NOT NULL,
  needs_doubling        BOOLEAN DEFAULT FALSE,
  production_length_mm  DECIMAL(10,2) NULL,
  notes                 TEXT NULL,
  sort_order            INT DEFAULT 0,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### orders
```sql
CREATE TABLE orders (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id            BIGINT UNSIGNED NOT NULL,
  order_no              VARCHAR(50) NOT NULL,
  quotation_id          BIGINT UNSIGNED NULL,
  customer_id           BIGINT UNSIGNED NOT NULL,
  project_name          VARCHAR(255) NULL,
  delivery_address      TEXT NULL,
  delivery_city         VARCHAR(100) NULL,
  delivery_state        VARCHAR(100) NULL,
  delivery_state_code   VARCHAR(5) NULL,
  order_date            DATE NOT NULL,
  expected_delivery     DATE NULL,
  committed_delivery    DATE NULL,
  priority              ENUM('normal','high','urgent') DEFAULT 'normal',
  total_sqm             DECIMAL(10,2) DEFAULT 0.00,
  grand_total           DECIMAL(12,2) DEFAULT 0.00,
  advance_amount        DECIMAL(12,2) DEFAULT 0.00,
  balance_due           DECIMAL(12,2) DEFAULT 0.00,
  is_intrastate         BOOLEAN DEFAULT TRUE,
  status                ENUM('confirmed','in_production','partially_produced',
                              'produced','partially_dispatched','dispatched',
                              'delivered','cancelled') DEFAULT 'confirmed',
  special_instructions  TEXT NULL,
  custom_fields         JSON NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL,
  UNIQUE KEY uk_order_no_company (order_no, company_id)
);
```

### order_items
```sql
CREATE TABLE order_items (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id            BIGINT UNSIGNED NOT NULL,
  order_id              BIGINT UNSIGNED NOT NULL,
  panel_type_id         BIGINT UNSIGNED NOT NULL,
  thickness_mm          INT NOT NULL,
  density_type          ENUM('puf','pir') NOT NULL,
  density_kg_m3         DECIMAL(5,2) NOT NULL,
  top_skin_material     VARCHAR(50) NOT NULL,
  top_skin_thickness_mm DECIMAL(4,2) NOT NULL,
  top_skin_color        VARCHAR(100) NULL,
  top_surface           ENUM('ribbed','plain') NOT NULL,
  bottom_skin_material  VARCHAR(50) NOT NULL,
  bottom_skin_thickness_mm DECIMAL(4,2) NOT NULL,
  bottom_skin_color     VARCHAR(100) NULL,
  bottom_surface        ENUM('ribbed','plain') NOT NULL,
  length_mm             DECIMAL(10,2) NOT NULL,
  width_mm              DECIMAL(10,2) DEFAULT 1000,
  nos                   INT NOT NULL,
  sqm                   DECIMAL(10,4) NOT NULL,
  final_rate            DECIMAL(10,2) NOT NULL,
  amount                DECIMAL(12,2) NOT NULL,
  hsn_code              VARCHAR(20) NOT NULL,
  needs_doubling        BOOLEAN DEFAULT FALSE,
  production_length_mm  DECIMAL(10,2) NULL,
  produced_nos          INT DEFAULT 0,
  dispatched_nos        INT DEFAULT 0,
  status                ENUM('pending','in_production','produced','dispatched') DEFAULT 'pending',
  sort_order            INT DEFAULT 0,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### production_batches
```sql
CREATE TABLE production_batches (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id        BIGINT UNSIGNED NOT NULL,
  batch_no          VARCHAR(50) NOT NULL,
  order_id          BIGINT UNSIGNED NOT NULL,
  order_item_id     BIGINT UNSIGNED NOT NULL,
  machine_id        BIGINT UNSIGNED NULL,
  shift             ENUM('day','night','general') DEFAULT 'day',
  planned_date      DATE NULL,
  planned_qty       INT NOT NULL,
  actual_qty        INT DEFAULT 0,
  rejected_qty      INT DEFAULT 0,
  planned_start     DATETIME NULL,
  actual_start      DATETIME NULL,
  actual_end        DATETIME NULL,
  operator_id       BIGINT UNSIGNED NULL,
  supervisor_id     BIGINT UNSIGNED NULL,
  status            ENUM('scheduled','in_progress','completed','on_hold','cancelled') DEFAULT 'scheduled',
  production_notes  TEXT NULL,
  created_by        BIGINT UNSIGNED NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_batch_no_company (batch_no, company_id)
);
```

### production_stage_logs
```sql
CREATE TABLE production_stage_logs (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id        BIGINT UNSIGNED NOT NULL,
  batch_id          BIGINT UNSIGNED NOT NULL,
  stage_config_id   BIGINT UNSIGNED NOT NULL,
  stage_name        VARCHAR(255) NOT NULL,
  stage_order       INT NOT NULL,
  started_at        DATETIME NULL,
  completed_at      DATETIME NULL,
  duration_minutes  INT NULL,
  operator_id       BIGINT UNSIGNED NULL,
  checklist_data    JSON NULL,
  parameters_logged JSON NULL,
  photos            JSON NULL,
  is_skipped        BOOLEAN DEFAULT FALSE,
  skip_reason       TEXT NULL,
  remarks           TEXT NULL,
  status            ENUM('pending','in_progress','completed','skipped') DEFAULT 'pending',
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### cutting_schedules
```sql
CREATE TABLE cutting_schedules (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    BIGINT UNSIGNED NOT NULL,
  schedule_no   VARCHAR(50) NOT NULL,
  schedule_date DATE NOT NULL,
  machine_id    BIGINT UNSIGNED NULL,
  shift         ENUM('day','night','general','all') DEFAULT 'day',
  total_panels  INT DEFAULT 0,
  total_sqm     DECIMAL(10,2) DEFAULT 0.00,
  status        ENUM('draft','active','completed','cancelled') DEFAULT 'draft',
  created_by    BIGINT UNSIGNED NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE cutting_schedule_items (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id            BIGINT UNSIGNED NOT NULL,
  schedule_id           BIGINT UNSIGNED NOT NULL,
  order_id              BIGINT UNSIGNED NOT NULL,
  order_item_id         BIGINT UNSIGNED NOT NULL,
  customer_name         VARCHAR(255) NULL,
  order_length_mm       DECIMAL(10,2) NOT NULL,
  production_length_mm  DECIMAL(10,2) NOT NULL,
  width_mm              DECIMAL(10,2) DEFAULT 1000,
  quantity              INT NOT NULL,
  sqm                   DECIMAL(10,4) NOT NULL,
  coil_id               BIGINT UNSIGNED NULL,
  sequence_no           INT NOT NULL,
  is_cut                BOOLEAN DEFAULT FALSE,
  cut_at                DATETIME NULL,
  wastage_mm            DECIMAL(10,2) DEFAULT 0,
  sort_order            INT DEFAULT 0,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### coil_stock
```sql
CREATE TABLE coil_stock (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id            BIGINT UNSIGNED NOT NULL,
  coil_tag_no           VARCHAR(100) NULL,
  supplier_id           BIGINT UNSIGNED NULL,
  material_type         ENUM('ppgi','ppgl','gi','ss304','aluminium','other') NOT NULL,
  thickness_mm          DECIMAL(4,2) NOT NULL,
  width_mm              INT DEFAULT 1000,
  color_code            VARCHAR(20) NULL,
  color_name            VARCHAR(100) NULL,
  total_weight_kg       DECIMAL(10,2) NOT NULL,
  consumed_weight_kg    DECIMAL(10,2) DEFAULT 0.00,
  remaining_weight_kg   DECIMAL(10,2) NOT NULL,
  purchase_rate_kg      DECIMAL(10,2) NULL,
  warehouse_location    VARCHAR(100) NULL,
  received_date         DATE NOT NULL,
  purchase_invoice_no   VARCHAR(100) NULL,
  minimum_alert_kg      DECIMAL(10,2) DEFAULT 50.00,
  status                ENUM('available','in_use','reserved','exhausted') DEFAULT 'available',
  notes                 TEXT NULL,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL
);
```

### dispatches
```sql
CREATE TABLE dispatches (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id            BIGINT UNSIGNED NOT NULL,
  dispatch_no           VARCHAR(50) NOT NULL,
  order_id              BIGINT UNSIGNED NOT NULL,
  customer_id           BIGINT UNSIGNED NOT NULL,
  dispatch_date         DATE NOT NULL,
  delivery_address      TEXT NULL,
  vehicle_no            VARCHAR(50) NULL,
  driver_name           VARCHAR(255) NULL,
  driver_phone          VARCHAR(20) NULL,
  transporter_name      VARCHAR(255) NULL,
  lr_no                 VARCHAR(100) NULL,
  eway_bill_no          VARCHAR(50) NULL,
  eway_bill_valid_till  DATETIME NULL,
  total_panels          INT DEFAULT 0,
  total_sqm             DECIMAL(10,2) DEFAULT 0.00,
  freight_amount        DECIMAL(10,2) DEFAULT 0.00,
  freight_paid_by       ENUM('manufacturer','customer') DEFAULT 'customer',
  is_partial            BOOLEAN DEFAULT FALSE,
  status                ENUM('prepared','in_transit','delivered','returned','cancelled') DEFAULT 'prepared',
  delivered_at          DATETIME NULL,
  receiver_name         VARCHAR(255) NULL,
  delivery_photos       JSON NULL,
  notes                 TEXT NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_dispatch_no_company (dispatch_no, company_id)
);
```

### invoices
```sql
CREATE TABLE invoices (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id            BIGINT UNSIGNED NOT NULL,
  invoice_no            VARCHAR(50) NOT NULL,
  invoice_type          ENUM('proforma','tax_invoice','credit_note','debit_note') NOT NULL,
  order_id              BIGINT UNSIGNED NULL,
  dispatch_id           BIGINT UNSIGNED NULL,
  customer_id           BIGINT UNSIGNED NOT NULL,
  invoice_date          DATE NOT NULL,
  due_date              DATE NULL,
  is_intrastate         BOOLEAN DEFAULT TRUE,
  subtotal              DECIMAL(12,2) DEFAULT 0.00,
  taxable_amount        DECIMAL(12,2) DEFAULT 0.00,
  cgst_amount           DECIMAL(12,2) DEFAULT 0.00,
  sgst_amount           DECIMAL(12,2) DEFAULT 0.00,
  igst_amount           DECIMAL(12,2) DEFAULT 0.00,
  total_gst             DECIMAL(12,2) DEFAULT 0.00,
  transportation_amount DECIMAL(10,2) DEFAULT 0.00,
  round_off             DECIMAL(6,2) DEFAULT 0.00,
  grand_total           DECIMAL(12,2) DEFAULT 0.00,
  amount_paid           DECIMAL(12,2) DEFAULT 0.00,
  balance_due           DECIMAL(12,2) DEFAULT 0.00,
  irn_number            VARCHAR(100) NULL,
  qr_code_data          TEXT NULL,
  status                ENUM('draft','sent','partially_paid','paid','overdue','cancelled') DEFAULT 'draft',
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL,
  UNIQUE KEY uk_invoice_no_company (invoice_no, company_id)
);
```

### payments
```sql
CREATE TABLE payments (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id        BIGINT UNSIGNED NOT NULL,
  payment_no        VARCHAR(50) NOT NULL,
  customer_id       BIGINT UNSIGNED NOT NULL,
  invoice_id        BIGINT UNSIGNED NULL,
  order_id          BIGINT UNSIGNED NULL,
  payment_date      DATE NOT NULL,
  amount            DECIMAL(12,2) NOT NULL,
  mode              ENUM('cash','cheque','neft','rtgs','upi','dd','other') NOT NULL,
  reference_no      VARCHAR(100) NULL,
  bank_name         VARCHAR(255) NULL,
  cheque_date       DATE NULL,
  cheque_status     ENUM('pending','deposited','cleared','bounced') NULL,
  is_advance        BOOLEAN DEFAULT FALSE,
  tds_amount        DECIMAL(10,2) DEFAULT 0.00,
  notes             TEXT NULL,
  received_by       BIGINT UNSIGNED NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_payment_no_company (payment_no, company_id)
);
```

### customers
```sql
CREATE TABLE customers (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id        BIGINT UNSIGNED NOT NULL,
  type              ENUM('direct','dealer','contractor','government','export') DEFAULT 'direct',
  name              VARCHAR(255) NOT NULL,
  company_name      VARCHAR(255) NULL,
  gstin             VARCHAR(20) NULL,
  phone             VARCHAR(20) NOT NULL,
  email             VARCHAR(255) NULL,
  whatsapp_no       VARCHAR(20) NULL,
  address_line1     VARCHAR(255) NULL,
  city              VARCHAR(100) NULL,
  state             VARCHAR(100) NULL,
  state_code        VARCHAR(5) NULL,
  pincode           VARCHAR(10) NULL,
  country           VARCHAR(100) DEFAULT 'India',
  credit_limit      DECIMAL(12,2) DEFAULT 0.00,
  payment_terms_days INT DEFAULT 0,
  notes             TEXT NULL,
  is_active         BOOLEAN DEFAULT TRUE,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at        TIMESTAMP NULL
);
```

### form_fields (Dynamic Form Builder)
```sql
CREATE TABLE form_fields (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id        BIGINT UNSIGNED NOT NULL,
  form_config_id    BIGINT UNSIGNED NOT NULL,
  field_key         VARCHAR(100) NOT NULL,
  field_label       VARCHAR(255) NOT NULL,
  field_type        ENUM('text','textarea','number','decimal','date','datetime',
                          'checkbox','dropdown','multi_select','file','phone','email') NOT NULL,
  is_system_field   BOOLEAN DEFAULT FALSE,
  is_custom_field   BOOLEAN DEFAULT FALSE,
  is_visible        BOOLEAN DEFAULT TRUE,
  is_required       BOOLEAN DEFAULT FALSE,
  is_printable      BOOLEAN DEFAULT TRUE,
  is_readonly       BOOLEAN DEFAULT FALSE,
  default_value     TEXT NULL,
  placeholder_text  VARCHAR(255) NULL,
  help_text         VARCHAR(500) NULL,
  sort_order        INT DEFAULT 0,
  dropdown_options  JSON NULL,
  validation_rules  JSON NULL,
  conditional_logic JSON NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_field_form (form_config_id, field_key)
);
```

---

## Table Relationships

```
companies (1) ──< users (M)
companies (1) ──< panel_types (M)
companies (1) ──< pricing_rules (M)
companies (1) ──< customers (M)
companies (1) ──< quotations (M)
quotations (1) ──< quotation_panel_items (M)
quotations (1) ──< orders (M)
orders (1) ──< order_items (M)
order_items (1) ──< production_batches (M)
production_batches (1) ──< production_stage_logs (M)
cutting_schedules (1) ──< cutting_schedule_items (M)
orders (1) ──< dispatches (M)
dispatches (1) ──< invoices (M)
invoices (1) ──< payments (M)
```

