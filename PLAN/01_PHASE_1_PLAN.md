# PHASE 1 — Foundation & Infrastructure

**Duration**: 3-4 weeks
**Team**: 1 Backend Lead + 1 DevOps (can be same person)
**Status**: 🔄 IN PROGRESS (Start Date: 2026-05-17)
**Target Completion**: 2026-06-07

---

## Overview

**Goal**: Build a solid, production-ready foundation that all other phases depend on.

**Deliverables**: 
1. Laravel 11 project structure
2. 51 database migrations with relationships
3. Sanctum authentication system
4. Multi-tenancy configuration
5. Base API response wrapper
6. Docker environment
7. CI/CD pipeline
8. Testing framework (80%+ coverage)

**Quality Gates** (before Phase 2):
- ✅ All tests pass
- ✅ Docker environment works locally
- ✅ Can login and get JWT token
- ✅ All migrations run clean
- ✅ Zero PHP warnings
- ✅ PSR-12 code style compliant

---

## Task Breakdown

### Task 1.1 — Project Initialization (Day 1-2)

**Objective**: Create Laravel 11 project with all dependencies

#### Sub-tasks:

```
1.1.1 ✅ Create Laravel 11 project
   Command: laravel new panelos-backend --git
   Expected: Fresh Laravel 11 installation
   Verify: composer.json exists, no errors

1.1.2 ✅ Install key packages
   Packages:
   - stancl/tenancy (multi-tenancy)
   - laravel/sanctum (API authentication)
   - spatie/laravel-permission (roles/permissions)
   - spatie/laravel-activitylog (audit logs)
   - barryvdh/laravel-dompdf (PDF generation)
   - guzzlehttp/guzzle (HTTP client)
   - php-cs-fixer (code style)
   - phpstan (static analysis)
   
   Command: composer require [packages]
   Expected: All packages installed
   Verify: vendor/ directory contains packages

1.1.3 ✅ Publish configurations
   Commands:
   - php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider"
   - php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
   - php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider"
   - php artisan vendor:publish --provider="Barryvdh\DomPDF\ServiceProvider"
   
   Expected: config/ directory updated with published configs
   Verify: tenancy.php, permission.php exist

1.1.4 ✅ Configure .env.example
   Set values:
   - APP_NAME=PanelOS
   - APP_ENV=local (for dev, production for prod)
   - APP_DEBUG=true (false in production)
   - APP_URL=http://localhost:8000
   - DB_CONNECTION=mysql
   - CACHE_DRIVER=redis
   - SESSION_DRIVER=redis
   - QUEUE_CONNECTION=redis
   
   Expected: .env.example ready for copying to .env
   Verify: All required keys present

1.1.5 ✅ Git setup
   Commands:
   - git init (if not done)
   - git config user.name "Claude"
   - git config user.email "claude@panelos.local"
   - Create .gitignore (exclude .env, node_modules, vendor, etc.)
   
   Expected: Git repo ready
   Verify: git log shows initial commit

Status: ⏳ PENDING
```

---

### Task 1.2 — Database Migrations (Day 3-7)

**Objective**: Create 51 tables with relationships, indices, constraints

#### Sub-tasks:

**Order 1: Core Tables (no dependencies)**

```
1.2.1 ✅ Create companies table
   Schema:
   - id (BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT)
   - name (VARCHAR 255)
   - subdomain (VARCHAR 100 UNIQUE)
   - gstin (VARCHAR 20)
   - address, city, state, pincode, etc.
   - colors (primary_color, secondary_color)
   - prefixes (quotation_prefix, invoice_prefix, order_prefix, challan_prefix)
   - subscription_plan (starter/growth/pro/enterprise)
   - created_at, updated_at, deleted_at (soft delete)
   
   Expected: Table created, no errors
   Verify: describe companies; shows correct columns

1.2.2 ✅ Create modules table
   Schema:
   - id, name, code, description, is_system
   
   Expected: Seed with: BOQ, Orders, Production, Inventory, Dispatch, Accounts, CRM, Reports, Notifications
   Verify: 9 modules in table

1.2.3 ✅ Create company_modules table
   Schema:
   - company_id, module_id, is_enabled
   - Foreign keys to companies, modules
   
   Expected: Junction table for company-module relationship
   Verify: Foreign key constraints exist

1.2.4 ✅ Create users table
   Schema:
   - id, company_id, name, email, phone, whatsapp_no, password
   - role_id, is_super_admin, is_company_admin, is_active
   - last_login_at, created_at, updated_at, deleted_at
   - UNIQUE KEY: uk_email_company (email, company_id)
   
   Expected: Multi-tenant user table with company isolation
   Verify: UNIQUE constraint on (email, company_id)

1.2.5 ✅ Create roles table
   Schema:
   - id, company_id, name, is_system, description
   - created_at, updated_at
   
   Expected: Table for 6 default roles (Owner, Sales Manager, Production Supervisor, etc.)
   Verify: company_id foreign key

1.2.6 ✅ Create role_permissions table
   Schema:
   - id, role_id, permission, company_id
   
   Expected: Many-to-many relationship between roles and permissions
   Verify: Foreign keys to roles, companies

Status: ⏳ PENDING

Order 2: Business Domain Tables

1.2.7 ✅ Create panel_types table
   Schema:
   - id, company_id, name, code, category (roof/wall/cold_room/ceiling)
   - core_material, hsn_code, gst_rate
   - standard_width_mm (always 1000), allow_custom_width
   - available_thicknesses (JSON), available_densities (JSON)
   - top_skin_materials, bottom_skin_materials (JSON)
   - default_top_surface, default_bottom_surface
   - min_production_length_mm (for doubling rule)
   - delivery_days_standard, warranty_months
   - is_active, sort_order
   
   Expected: Seed with Signature PUF 5 products
   Verify: JSON columns contain valid arrays

1.2.8 ✅ Create color_master table
   Schema:
   - id, company_id, color_code, color_name, hex_code, is_active
   
   Expected: Standard colors (Off White, Pearl White, Ivory, Mild Steel, etc.)
   Verify: At least 10 colors seeded

1.2.9 ✅ Create accessories_master table
   Schema:
   - id, company_id, name, code, unit (MTR/KG/NOS), hsn_code, gst_rate
   - is_active, sort_order
   
   Expected: Ridge caps, L-angles, doors, windows, etc.
   Verify: Different units (MTR, NOS) present

1.2.10 ✅ Create pricing_rules table
   Schema:
   - id, company_id, base_rate, thickness_mm, density_type
   - top_skin_material, top_skin_thickness, bottom_skin_material
   - premium_pct, customer_type_multiplier, region_multiplier
   - quality_grade_multiplier
   - created_at, updated_at
   
   Expected: Pricing configuration with premiums
   Verify: Multiple rules for different combinations

1.2.11 ✅ Create quantity_slabs table
   Schema:
   - id, company_id, from_sqm, to_sqm, discount_pct
   
   Expected: 3-4 slabs (0-500: 0%, 501-1000: 5%, 1000+: 10%)
   Verify: Ranges don't overlap

Status: ⏳ PENDING

Order 3: Transaction Tables (Quotations & Orders)

1.2.12 ✅ Create quotations table
   Schema:
   - id, company_id, quotation_no, version, parent_quotation_id
   - enquiry_id, customer_id
   - project_name, project_location, quotation_date
   - validity_days, valid_until, quality_grade
   - total_sqm, panel_subtotal, accessory_subtotal, subtotal
   - discount_pct, discount_amount, taxable_amount
   - cgst_amount, sgst_amount, igst_amount, total_gst
   - transportation_type, transportation_amount, grand_total
   - advance_amount, balance_amount
   - is_intrastate, status (draft/sent/accepted/rejected/revised/expired)
   - notes, internal_notes, custom_fields (JSON)
   - created_by, created_at, updated_at, deleted_at
   - UNIQUE KEY: uk_quotation_no_company (quotation_no, company_id)
   
   Expected: Main quotation table
   Verify: UNIQUE constraint prevents duplicate numbers per company per year

1.2.13 ✅ Create quotation_panel_items table
   Schema:
   - id, company_id, quotation_id, panel_type_id
   - thickness_mm, density_type (puf/pir), density_kg_m3
   - top_skin_material, top_skin_thickness_mm, top_skin_color
   - top_surface (ribbed/plain), bottom_skin_material, bottom_skin_thickness_mm
   - bottom_skin_color, bottom_surface, guard_film, cello_tap
   - length_mm, width_mm (1000, read-only), nos, sqm (auto), final_rate
   - amount (auto), hsn_code, gst_rate
   - needs_doubling (boolean, for < 2000mm rule)
   - production_length_mm (for doubling calculation)
   - notes, sort_order
   
   Expected: Panel line items for quotation
   Verify: Foreign key to quotations, panel_types

1.2.14 ✅ Create quotation_accessory_items table
   Schema:
   - id, company_id, quotation_id
   - accessory_id, description, unit, qty, rate, amount
   - hsn_code, gst_rate, notes, sort_order
   
   Expected: Accessory line items
   Verify: Foreign keys to quotations, accessories_master

1.2.15 ✅ Create customers table
   Schema:
   - id, company_id, type (direct/dealer/contractor/government/export)
   - name, company_name, gstin, phone, email, whatsapp_no
   - address_line1, city, state, state_code, pincode, country
   - credit_limit, payment_terms_days, notes, is_active
   - created_at, updated_at, deleted_at
   
   Expected: Customer master
   Verify: state_code for GST calculation

Status: ⏳ PENDING

Order 4: Orders, Production, Inventory Tables

1.2.16 ✅ Create orders table
   Schema:
   - id, company_id, order_no, quotation_id, customer_id
   - project_name, delivery_address, delivery_city, delivery_state
   - delivery_state_code, order_date, expected_delivery
   - committed_delivery, priority (normal/high/urgent)
   - total_sqm, grand_total, advance_amount, balance_due
   - is_intrastate, status (confirmed/in_production/.../delivered/cancelled)
   - special_instructions, custom_fields, created_by
   - created_at, updated_at, deleted_at
   - UNIQUE KEY: uk_order_no_company
   
   Expected: Order master
   Verify: Status workflow defined

1.2.17 ✅ Create order_items table
   Schema:
   - id, company_id, order_id, panel_type_id
   - thickness_mm, density_type, density_kg_m3
   - top_skin_material, top_skin_thickness_mm, top_skin_color, top_surface
   - bottom_skin_material, bottom_skin_thickness_mm, bottom_skin_color, bottom_surface
   - length_mm, width_mm, nos, sqm, final_rate, amount, hsn_code
   - needs_doubling, production_length_mm
   - produced_nos, dispatched_nos
   - status (pending/in_production/produced/dispatched)
   
   Expected: Order line items
   Verify: Copy of quotation_panel_items structure

1.2.18 ✅ Create production_batches table
   Schema:
   - id, company_id, batch_no, order_id, order_item_id
   - machine_id, shift (day/night/general)
   - planned_date, planned_qty, actual_qty, rejected_qty
   - planned_start, actual_start, actual_end
   - operator_id, supervisor_id
   - status (scheduled/in_progress/completed/on_hold/cancelled)
   - production_notes, created_by
   - created_at, updated_at
   - UNIQUE KEY: uk_batch_no_company
   
   Expected: Production batch master
   Verify: Foreign keys to orders, machines, users

1.2.19 ✅ Create production_stage_logs table
   Schema:
   - id, company_id, batch_id, stage_config_id
   - stage_name, stage_order
   - started_at, completed_at, duration_minutes
   - operator_id
   - checklist_data (JSON: {key: boolean})
   - parameters_logged (JSON: {key: value})
   - photos (JSON: [url1, url2])
   - is_skipped, skip_reason
   - remarks
   - status (pending/in_progress/completed/skipped)
   
   Expected: Stage-by-stage tracking
   Verify: JSON columns for flexible data

1.2.20 ✅ Create cutting_schedules table
   Schema:
   - id, company_id, schedule_no, schedule_date
   - machine_id, shift (day/night/general/all)
   - total_panels, total_sqm
   - status (draft/active/completed/cancelled)
   - created_by, created_at, updated_at

1.2.21 ✅ Create cutting_schedule_items table
   Schema:
   - id, company_id, schedule_id
   - order_id, order_item_id
   - customer_name, order_length_mm, production_length_mm
   - width_mm, quantity, sqm
   - coil_id, sequence_no, is_cut, cut_at
   - wastage_mm, sort_order
   
   Expected: Line items for cutting schedule
   Verify: Logic for doubling (order_length vs production_length)

1.2.22 ✅ Create coil_stock table
   Schema:
   - id, company_id, coil_tag_no
   - supplier_id, material_type (ppgi/ppgl/gi/ss304/aluminium)
   - thickness_mm, width_mm, color_code, color_name
   - total_weight_kg, consumed_weight_kg, remaining_weight_kg
   - purchase_rate_kg, warehouse_location
   - received_date, purchase_invoice_no
   - minimum_alert_kg, status (available/in_use/reserved/exhausted)
   - notes, created_at, updated_at, deleted_at
   
   Expected: Coil inventory master
   Verify: Weight tracking (kg)

1.2.23 ✅ Create inventory_transactions table
   Schema:
   - id, company_id, transaction_type
   - (purchase_in/production_consume/wastage/adjustment_in/adjustment_out)
   - material_type, material_id (coil_id, chemical_id, etc.)
   - qty, unit, batch_id, reason, created_by
   - created_at (NO updated_at — immutable)
   
   Expected: Immutable transaction log
   Verify: No update_at column, enforce immutability in code

1.2.24 ✅ Create dispatches table
   Schema:
   - id, company_id, dispatch_no, order_id, customer_id
   - dispatch_date, delivery_address
   - vehicle_no, driver_name, driver_phone
   - transporter_name, lr_no, eway_bill_no, eway_bill_valid_till
   - total_panels, total_sqm
   - freight_amount, freight_paid_by (manufacturer/customer)
   - is_partial, status (prepared/in_transit/delivered/returned/cancelled)
   - delivered_at, receiver_name, delivery_photos (JSON)
   - notes, created_by, created_at, updated_at
   - UNIQUE KEY: uk_dispatch_no_company
   
   Expected: Dispatch master
   Verify: E-way bill fields for inter-state

Status: ⏳ PENDING

Order 5: Accounting & GST Tables

1.2.25 ✅ Create invoices table
   Schema:
   - id, company_id, invoice_no, invoice_type (proforma/tax_invoice/credit_note/debit_note)
   - order_id, dispatch_id, customer_id
   - invoice_date, due_date
   - is_intrastate, subtotal, taxable_amount
   - cgst_amount, sgst_amount, igst_amount, total_gst
   - transportation_amount, round_off, grand_total
   - amount_paid, balance_due
   - irn_number (for e-invoice), qr_code_data
   - status (draft/sent/partially_paid/paid/overdue/cancelled)
   - created_by, created_at, updated_at, deleted_at
   - UNIQUE KEY: uk_invoice_no_company
   
   Expected: Invoice master
   Verify: Invoice type enum

1.2.26 ✅ Create payments table
   Schema:
   - id, company_id, payment_no
   - customer_id, invoice_id, order_id
   - payment_date, amount
   - mode (cash/cheque/neft/rtgs/upi/dd/other)
   - reference_no, bank_name
   - cheque_date, cheque_status (pending/deposited/cleared/bounced)
   - is_advance, tds_amount
   - notes, received_by
   - created_at, updated_at
   - UNIQUE KEY: uk_payment_no_company
   
   Expected: Payment master
   Verify: cheque_status enum

1.2.27 ✅ Create form_configs table
   Schema:
   - id, company_id, form_type
   - (boq_header, panel_row, customer, order, dispatch, invoice, etc.)
   - form_name, created_at, updated_at
   - UNIQUE KEY: uk_form_company
   
   Expected: Form configuration master
   Verify: form_type enum

1.2.28 ✅ Create form_fields table
   Schema:
   - id, company_id, form_config_id
   - field_key, field_label, field_type
   - (text, textarea, number, decimal, date, checkbox, dropdown, etc.)
   - is_system_field, is_custom_field
   - is_visible, is_required, is_printable, is_readonly
   - default_value, placeholder_text, help_text
   - sort_order
   - dropdown_options (JSON), validation_rules (JSON)
   - conditional_logic (JSON)
   - created_at, updated_at
   - UNIQUE KEY: uk_field_form
   
   Expected: Dynamic form fields
   Verify: JSON columns for options and logic

1.2.29 ✅ Create audit_logs table
   Schema:
   - id, company_id, user_id
   - action, model_type, model_id
   - old_values (JSON), new_values (JSON)
   - ip_address, user_agent
   - created_at (NO updates — immutable)
   
   Expected: Immutable audit trail for 7-year compliance
   Verify: No update_at

1.2.30 ✅ Create machines table
   Schema:
   - id, company_id, name, code
   - type, capacity_day_sqm
   - day_shift_start, day_shift_end
   - night_shift_start, night_shift_end
   - last_maintenance, next_maintenance
   - status (active/maintenance/breakdown/idle)
   - notes, created_at, updated_at
   
   Expected: Machine master
   Verify: Shift times configurable

1.2.31 ✅ Create quality_logs table
   Schema:
   - id, company_id, batch_id
   - thickness_actual_mm, thickness_tolerance
   - density_actual_kg_m3, density_tolerance
   - foam_quality, surface_quality, skin_adhesion, edge_quality
   - overall_result (pass/fail/rework)
   - total_checked, rejected_count, rejection_reason
   - defects (JSON: [scratch, dent, delamination, etc.])
   - corrective_action
   - qc_user_id, created_at, updated_at
   
   Expected: QC entry master
   Verify: JSON defects array

1.2.32 ✅ Create suppliers table
   Schema:
   - id, company_id, name, type
   - gstin, phone, email
   - address_line1, city, state, pincode
   - bank_name, bank_account, bank_ifsc
   - is_active, created_at, updated_at, deleted_at
   
   Expected: Supplier master
   Verify: GST for purchase invoices

1.2.33 ✅ Create purchase_orders table
   Schema:
   - id, company_id, po_no, supplier_id
   - po_date, expected_receive_date
   - total_amount, status (draft/sent/partially_received/received/closed)
   - created_by, created_at, updated_at
   
   Expected: Purchase order master
   Verify: Status workflow

1.2.34 ✅ Create personal_access_tokens table
   Schema:
   - Sanctum built-in table
   - (id, tokenable_type, tokenable_id, name, token, abilities, last_used_at, created_at, updated_at)
   
   Expected: Created by Sanctum during migration
   Verify: Generated automatically

Status: ⏳ PENDING

Final Verification:
- [ ] All 51 tables created
- [ ] All foreign keys configured
- [ ] All indices added
- [ ] All UNIQUE constraints
- [ ] Soft deletes on: quotations, orders, customers, users, panel_types, etc.
- [ ] Audit columns on: created_by, updated_by on critical tables
- [ ] JSON columns for flexible config
- [ ] No errors: php artisan migrate

Status: ⏳ PENDING
```

---

### Task 1.3 — Authentication & Authorization (Day 8-10)

**Objective**: Implement Sanctum auth + role-based access control

#### Sub-tasks:

```
1.3.1 ✅ Implement User model
   - Use HasTenantScoped trait (auto WHERE company_id)
   - Password hashing (bcrypt)
   - Relationships: roles(), company()
   
1.3.2 ✅ Implement Role model
   - Relationships: users(), permissions()
   
1.3.3 ✅ POST /auth/login endpoint
   - Input: email, password
   - Validation: email required, password required
   - Logic: Find user, verify password, issue token
   - Response: { success: true, data: { token, user } }
   
1.3.4 ✅ POST /auth/logout endpoint
   - Revoke current token
   - Response: { success: true, message: "Logged out" }
   
1.3.5 ✅ GET /auth/me endpoint
   - Return current user + roles
   
1.3.6 ✅ POST /auth/refresh-token endpoint
   - Refresh expiring token
   
1.3.7 ✅ POST /auth/change-password endpoint
   - Input: current_password, new_password, confirm_password
   - Verify current password, update, return success
   
1.3.8 ✅ Sanctum middleware configuration
   - API routes protected by auth:sanctum
   - Tenant middleware (auto-scope to company_id)
   
1.3.9 ✅ Seed 6 default roles
   - Owner
   - Sales Manager
   - Production Supervisor
   - Floor Worker
   - Accountant
   - Dispatch Executive
   
1.3.10 ✅ Test all auth flows
   - Login with valid credentials → token
   - Login with invalid → 401
   - Logout → token revoked
   - Get /me → user + roles
   - Token expiration → 401 after expiry

Status: ⏳ PENDING
```

---

### Task 1.4 — API Response Wrapper (Day 11)

**Objective**: Consistent response format across all endpoints

#### Sub-tasks:

```
1.4.1 ✅ Create ApiResponse trait
   
   class ApiResponse {
     public function success($data, $message = null, $meta = null)
       → { success: true, data, message, meta }
     
     public function error($message, $errors = null, $code = null)
       → { success: false, message, errors, error_code }
   }

1.4.2 ✅ Create BaseController extending Controller
   - Use ApiResponse trait
   - All controllers extend BaseController

1.4.3 ✅ Handle exceptions globally
   - Handler: App\Exceptions\Handler
   - Catch: ValidationException, ModelNotFoundException, etc.
   - Return: ApiResponse error format

1.4.4 ✅ HTTP status codes
   - 200: Success
   - 201: Created
   - 204: No Content
   - 400: Bad Request
   - 401: Unauthorized
   - 403: Forbidden
   - 404: Not Found
   - 422: Validation Error
   - 500: Server Error

1.4.5 ✅ Test response format
   - Make request, verify structure
   - Check status code correct
   - Verify error messages helpful (not technical)

Status: ⏳ PENDING
```

---

### Task 1.5 — Base Models & Traits (Day 12)

**Objective**: DRY code through reusable traits

#### Sub-tasks:

```
1.5.1 ✅ Create HasTenantScoped trait
   - Override newQuery() to add: ->where('company_id', auth()->user()->company_id)
   - Applied to: Quotation, Order, Invoice, Customer, etc.
   - Result: All queries auto-scoped to company

1.5.2 ✅ Create HasAuditLog trait
   - created_by, updated_by columns
   - Automatically set in model observers
   - Track who created/modified each record

1.5.3 ✅ Create SoftDeletesWithCompany trait
   - Extend SoftDeletes + HasTenantScoped
   - Soft-delete with company scope

1.5.4 ✅ Create BaseModel extending Model
   - Use Carbon date casting
   - Use HashIds (obfuscate numeric IDs in API)
   - JSON casts for config columns

1.5.5 ✅ Create model factories for testing
   - UserFactory
   - CompanyFactory
   - QuotationFactory
   - OrderFactory
   - etc.

1.5.6 ✅ Test trait functionality
   - Create quotation in Company A
   - Login as Company B user
   - Verify quota not visible (company_id scope working)

Status: ⏳ PENDING
```

---

### Task 1.6 — Testing Infrastructure (Day 13-14)

**Objective**: 80%+ code coverage from day 1

#### Sub-tasks:

```
1.6.1 ✅ Configure PHPUnit
   - phpunit.xml with test database
   - Test environment: .env.testing
   - Database: sqlite :memory: for speed

1.6.2 ✅ Create test traits
   - RefreshDatabase: Reset DB after each test
   - Authenticate: Login as user automatically
   - WithTenant: Set company context

1.6.3 ✅ Write tests for Auth
   
   Tests/Feature/AuthTest.php:
   - testLoginWithValidCredentials() → 200, token
   - testLoginWithInvalidEmail() → 401
   - testLoginWithInvalidPassword() → 401
   - testLogout() → token revoked
   - testGetCurrentUser() → 200, user data
   - testUnauthorizedAccessWithoutToken() → 401

1.6.4 ✅ Write tests for Model relationships
   
   Tests/Unit/Models/QuotationTest.php:
   - testQuotationBelongsToCustomer()
   - testQuotationHasManyPanelItems()
   - testQuotationAutoScoped ToCompany()
   - testSoftDeleteWorks()

1.6.5 ✅ Write tests for API Response
   
   Tests/Feature/ApiResponseTest.php:
   - testSuccessResponseFormat()
   - testErrorResponseFormat()
   - testValidationErrorResponse()

1.6.6 ✅ Run tests
   Command: php artisan test
   Expected: All tests pass, > 80% coverage

1.6.7 ✅ Generate coverage report
   Command: php artisan test --coverage
   Expected: Display coverage percentage

Status: ⏳ PENDING
```

---

### Task 1.7 — Docker Environment (Day 15)

**Objective**: Reproducible local development + staging/production-ready

#### Sub-tasks:

```
1.7.1 ✅ Create docker-compose.yml
   Services:
   - app (Laravel FPM)
   - nginx (web server)
   - mysql (database)
   - redis (cache/queue)
   - node (frontend build)

1.7.2 ✅ Create Dockerfile
   Base: php:8.2-fpm
   Dependencies: composer, extensions (redis, mysql, gd, curl, zip)
   Copy: source code, run composer install

1.7.3 ✅ Create nginx.conf
   Upstream: php:9000
   Server block: Listen 80, root /var/www/html/public
   Try files: $uri, $uri/, /index.php?$query_string

1.7.4 ✅ Create .dockerignore
   Exclude: vendor, node_modules, .git, .env

1.7.5 ✅ Start services
   Command: docker-compose up -d
   Expected: All 5 services running
   Verify: docker-compose ps

1.7.6 ✅ Run migrations in container
   Command: docker-compose exec app php artisan migrate
   Expected: All tables created

1.7.7 ✅ Seed default data
   Command: docker-compose exec app php artisan db:seed
   Expected: Companies, modules, roles seeded

1.7.8 ✅ Access application
   URL: http://localhost
   Expected: Laravel landing page

Status: ⏳ PENDING
```

---

### Task 1.8 — CI/CD Pipeline (Day 16)

**Objective**: Automated testing + deployment

#### Sub-tasks:

```
1.8.1 ✅ Create .github/workflows/tests.yml
   
   Trigger: on push, pull_request
   
   Jobs:
   - Checkout code
   - Setup PHP 8.2
   - Install composer packages
   - Copy .env.testing
   - Create database
   - Run migrations
   - Run tests (php artisan test)
   - Check code style (php-cs-fixer)
   - Static analysis (phpstan)
   - Upload coverage report

1.8.2 ✅ Create pr-title-lint.yml
   Check: PR title follows convention
   (feat: ..., fix: ..., docs: ..., refactor: ...)

1.8.3 ✅ Test workflow
   Commit something, push to branch
   Verify: GitHub Actions runs tests
   Expected: All checks pass

Status: ⏳ PENDING
```

---

### Task 1.9 — Documentation (Day 17)

**Objective**: Clear setup + architectural decisions

#### Sub-tasks:

```
1.9.1 ✅ Update README.md
   Sections:
   - Project overview
   - Tech stack
   - Quick start (Docker)
   - Database setup
   - Running tests
   - Contributing guidelines
   - License

1.9.2 ✅ Create ARCHITECTURE.md
   - System design
   - Layers: API, Service, Repository, Model
   - Multi-tenancy approach
   - Authentication flow
   - Error handling

1.9.3 ✅ Create DECISIONS.md
   - ADR: Why Laravel 11?
   - ADR: Why stancl/tenancy?
   - ADR: Why Sanctum?
   - ADR: Why Redis?

1.9.4 ✅ Create API.md (preliminary)
   - List all endpoints created in Phase 1
   - (Auth endpoints, not full API yet)

1.9.5 ✅ Create SETUP.md
   - Local development setup
   - Docker setup
   - Environment variables
   - Running migrations
   - Running tests
   - Running dev server

Status: ⏳ PENDING
```

---

## Quality Gates (Before Phase 2)

All of these MUST pass:

```
✅ Code Quality
   ☐ All tests pass: php artisan test
   ☐ Coverage > 80%: php artisan test --coverage
   ☐ No PHP warnings: PHP linter clean
   ☐ PSR-12 compliant: php-cs-fixer fix
   ☐ Static analysis: phpstan level 5

✅ Database
   ☐ All 51 migrations run: php artisan migrate:fresh
   ☐ All seeders complete: php artisan db:seed
   ☐ No foreign key errors
   ☐ Soft deletes work correctly
   ☐ Relationships tested

✅ Authentication
   ☐ Login works: POST /auth/login → token
   ☐ Logout works: POST /auth/logout → revoked
   ☐ Token required for protected endpoints
   ☐ Tenant isolation: Company A users can't see Company B data
   ☐ Role-based access working

✅ API
   ☐ All endpoints return ApiResponse format
   ☐ Error messages helpful
   ☐ HTTP status codes correct
   ☐ Validation errors formatted correctly

✅ Docker
   ☐ docker-compose up -d starts all services
   ☐ All services healthy
   ☐ Can run migrations in container
   ☐ Can run tests in container
   ☐ localhost:80 accessible

✅ CI/CD
   ☐ GitHub Actions workflow runs on push
   ☐ All tests pass in CI
   ☐ Coverage reported
   ☐ No red checks on main branch

✅ Documentation
   ☐ README clear
   ☐ ARCHITECTURE documented
   ☐ SETUP guide works
   ☐ DECISIONS recorded
```

---

## Deliverables Checklist

- [ ] Laravel 11 project structure
- [ ] 51 database tables with relationships
- [ ] All migrations run cleanly
- [ ] Sanctum authentication system
- [ ] Multi-tenancy configured (stancl/tenancy)
- [ ] API response wrapper (consistent format)
- [ ] Base models + traits (TenantScoped, AuditLog)
- [ ] Docker environment (app, nginx, mysql, redis, node)
- [ ] GitHub Actions CI/CD pipeline
- [ ] PHPUnit testing framework (80%+ coverage)
- [ ] 6 default roles seeded
- [ ] README.md with quick start
- [ ] ARCHITECTURE.md
- [ ] DECISIONS.md (ADRs)
- [ ] SETUP.md
- [ ] API.md (preliminary)
- [ ] All tests passing
- [ ] Zero PHP warnings
- [ ] PSR-12 compliant
- [ ] Ready for Phase 2 start

---

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Foreign key constraints too strict | Test thoroughly before production |
| Multi-tenancy data leak | Add security tests, verify company_id in all queries |
| Database migration conflicts | Use atomic migrations, test fresh migration |
| Tests flaky or slow | Use sqlite :memory:, mock external calls |
| Docker networking issues | Document port mappings, provide troubleshooting |

---

## Notes

- This phase is the foundation for everything after
- Get it right — avoid refactoring later
- Quality > Speed
- All tests must pass before moving to Phase 2
- Documentation as you go

