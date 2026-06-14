# 11 — Setup Guide

> Complete installation, environment setup, deployment guide.
> From zero to running PanelOS in development and production.

---

## Table of Contents

1. [System Requirements](#1-system-requirements)
2. [Local Development Setup](#2-local-development-setup)
3. [Backend Setup — Laravel 11](#3-backend-setup--laravel-11)
4. [Frontend Setup — Vue 3](#4-frontend-setup--vue-3)
5. [Database Setup & Migrations](#5-database-setup--migrations)
6. [Seeding Default Data](#6-seeding-default-data)
7. [Environment Variables Reference](#7-environment-variables-reference)
8. [First Company Setup](#8-first-company-setup)
9. [Production Deployment](#9-production-deployment)
10. [Server Configuration](#10-server-configuration)
11. [Maintenance & Updates](#11-maintenance--updates)
12. [Troubleshooting](#12-troubleshooting)

---

## 1. System Requirements

### Server Requirements

```
PHP:        >= 8.2
Composer:   >= 2.x
Node.js:    >= 20.x (LTS)
NPM:        >= 10.x
MySQL:      >= 8.0
Redis:      >= 7.0
Nginx:      >= 1.20 (recommended) OR Apache 2.4+
SSL:        Required for production (Let's Encrypt)

PHP Extensions Required:
  php-bcmath
  php-ctype
  php-fileinfo
  php-json
  php-mbstring
  php-openssl
  php-pdo
  php-pdo_mysql
  php-tokenizer
  php-xml
  php-zip
  php-gd          (for PDF generation)
  php-curl        (for WhatsApp/SMS API)
  php-redis       (for Redis cache)
  php-intl        (for number formatting)

Optional but recommended:
  php-imagick     (better image handling in PDFs)
  php-opcache     (PHP performance)
```

### Development Machine Requirements
```
OS:         Windows 10/11, macOS 12+, Ubuntu 22+
RAM:        8 GB minimum (16 GB recommended)
Disk:       20 GB free
Browser:    Chrome 120+ or Firefox 120+

Tools:
  Git
  VS Code (recommended) with extensions:
    - Volar (Vue)
    - PHP Intelephense
    - Laravel Blade Snippets
    - Tailwind CSS IntelliSense
    - GitLens
```

---

## 2. Local Development Setup

### Option A — Using Laragon (Windows — Recommended)

```bash
# 1. Download and install Laragon Full from laragon.org
# 2. Laragon includes: PHP 8.2, MySQL 8, Redis, Nginx, Node.js

# 3. Clone the project
cd C:\laragon\www
git clone https://github.com/your-repo/panelos.git
cd panelos

# 4. Laragon auto-detects project
# Access at: http://panelos.test

# 5. Add virtual hosts for subdomains
# Laragon Menu → Apache/Nginx → sites-enabled
# Add: *.panelosapp.test → panelos/backend/public
```

### Option B — Using Herd (macOS — Recommended)

```bash
# 1. Download Laravel Herd from herd.laravel.com
# Herd includes PHP 8.2, Node.js

# 2. Install Redis separately
brew install redis
brew services start redis

# 3. Install MySQL
brew install mysql@8.0
brew services start mysql@8.0

# 4. Clone project
cd ~/Herd
git clone https://github.com/your-repo/panelos.git

# Herd auto-serves at: http://panelos.test
```

### Option C — Docker

```yaml
# docker-compose.yml
version: '3.8'
services:

  app:
    build:
      context: .
      dockerfile: docker/Dockerfile
    ports:
      - "8000:8000"
    volumes:
      - ./backend:/var/www/html
    environment:
      - APP_ENV=local
    depends_on:
      - mysql
      - redis

  nginx:
    image: nginx:alpine
    ports:
      - "80:80"
    volumes:
      - ./backend:/var/www/html
      - ./docker/nginx.conf:/etc/nginx/conf.d/default.conf

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: panelos
      MYSQL_ROOT_PASSWORD: secret
      MYSQL_USER: panelos
      MYSQL_PASSWORD: secret
    ports:
      - "3306:3306"
    volumes:
      - mysql_data:/var/lib/mysql

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

  node:
    image: node:20-alpine
    working_dir: /app
    volumes:
      - ./frontend:/app
    command: sh -c "npm install && npm run dev"
    ports:
      - "5173:5173"

volumes:
  mysql_data:
```

```bash
# Start with Docker
docker-compose up -d
docker-compose exec app php artisan migrate --seed
```

---

## 3. Backend Setup — Laravel 11

### Step 1: Clone & Install

```bash
cd panelos/backend

# Install PHP dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate
```

### Step 2: Configure .env

```bash
# Open .env and configure (see Section 7 for full reference)
APP_NAME="PanelOS"
APP_ENV=local
APP_KEY=base64:...  # auto-generated
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=panelos
DB_USERNAME=root
DB_PASSWORD=your_password

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

### Step 3: Create Database

```bash
# MySQL
mysql -u root -p
CREATE DATABASE panelos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'panelos'@'localhost' IDENTIFIED BY 'strong_password';
GRANT ALL PRIVILEGES ON panelos.* TO 'panelos'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Step 4: Run Migrations

```bash
php artisan migrate
```

### Step 5: Install Key Packages

```bash
# Multi-tenancy
composer require stancl/tenancy

# PDF generation
composer require barryvdh/laravel-dompdf

# Activity log (audit trail)
composer require spatie/laravel-activitylog

# Auth
# Laravel Sanctum comes built-in with Laravel 11

# Publish configs
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider"
php artisan vendor:publish --provider="Barryvdh\DomPDF\ServiceProvider"
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider"
```

### Step 6: Configure Tenancy

```php
// config/tenancy.php — key settings
return [
    'tenant_model' => \App\Models\Company::class,

    'identification_drivers' => [
        'subdomain' => [
            'driver' => 'subdomain',
            'subdomain_column' => 'subdomain',
        ],
    ],

    'central_domains' => [
        'panelosapp.com',        // production
        'panelosapp.test',       // local dev
        'admin.panelosapp.com',  // super admin
    ],
];
```

### Step 7: Configure Queue Worker

```bash
# Development — run in separate terminal
php artisan queue:work --queue=default,pdf,notifications

# Production — use Supervisor (see Section 9)
```

### Step 8: Start Development Server

```bash
php artisan serve
# API available at: http://localhost:8000/api/v1/
```

### Laravel Package List — Full

```json
// composer.json — require section
{
  "require": {
    "php": "^8.2",
    "laravel/framework": "^11.0",
    "laravel/sanctum": "^4.0",
    "stancl/tenancy": "^3.8",
    "barryvdh/laravel-dompdf": "^2.1",
    "spatie/laravel-activitylog": "^4.7",
    "spatie/laravel-permission": "^6.3",
    "league/flysystem-aws-s3-v3": "^3.0",
    "guzzlehttp/guzzle": "^7.8"
  },
  "require-dev": {
    "fakerphp/faker": "^1.23",
    "laravel/pint": "^1.13",
    "laravel/sail": "^1.26",
    "mockery/mockery": "^1.6",
    "nunomaduro/collision": "^8.0",
    "phpunit/phpunit": "^11.0"
  }
}
```

---

## 4. Frontend Setup — Vue 3

### Step 1: Install Dependencies

```bash
cd panelos/frontend
npm install
```

### Step 2: Configure .env.local

```bash
cp .env.example .env.local

# Edit .env.local:
VITE_API_BASE_URL=http://localhost:8000
VITE_APP_NAME=PanelOS
VITE_APP_ENV=development
```

### Step 3: Start Dev Server

```bash
npm run dev
# Frontend at: http://localhost:5173
```

### NPM Package List — Full

```json
{
  "dependencies": {
    "vue": "^3.4.0",
    "@inertiajs/vue3": "^1.0.0",
    "pinia": "^2.1.7",
    "vue-router": "^4.3.0",
    "primevue": "^4.0.0",
    "@primevue/themes": "^4.0.0",
    "primeicons": "^7.0.0",
    "axios": "^1.6.0",
    "dayjs": "^1.11.10",
    "@vueuse/core": "^10.7.0",
    "chart.js": "^4.4.0",
    "sortablejs": "^1.15.0"
  },
  "devDependencies": {
    "@vitejs/plugin-vue": "^5.0.0",
    "vite": "^5.0.0",
    "tailwindcss": "^3.4.0",
    "autoprefixer": "^10.4.0",
    "postcss": "^8.4.0",
    "@tailwindcss/forms": "^0.5.0"
  }
}
```

### Vite Configuration

```javascript
// vite.config.js
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'url'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true
      }
    }
  }
})
```

### Tailwind Configuration

```javascript
// tailwind.config.js
export default {
  content: [
    './index.html',
    './src/**/*.{vue,js,ts}',
  ],
  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: '#1a237e',
          light: '#3949ab',
          dark: '#0d1257',
        },
        accent: {
          DEFAULT: '#f57f17',
          light: '#ffb300',
        }
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
        mono: ['Roboto Mono', 'monospace'],
      }
    }
  },
  plugins: [
    require('@tailwindcss/forms'),
  ]
}
```

### Main App Entry (main.js)

```javascript
// src/main.js
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import router from './router'
import App from './App.vue'

// PrimeVue
import PrimeVue from 'primevue/config'
import { definePreset } from '@primevue/themes'
import Aura from '@primevue/themes/aura'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import Tooltip from 'primevue/tooltip'

// PrimeVue Components (auto-import or manual)
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Dropdown from 'primevue/dropdown'
import DatePicker from 'primevue/datepicker'
import Checkbox from 'primevue/checkbox'
import RadioButton from 'primevue/radiobutton'
import Textarea from 'primevue/textarea'
import Dialog from 'primevue/dialog'
import Tag from 'primevue/tag'
import Badge from 'primevue/badge'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import Message from 'primevue/message'
import Card from 'primevue/card'
import Skeleton from 'primevue/skeleton'
import ProgressBar from 'primevue/progressbar'
import Breadcrumb from 'primevue/breadcrumb'
import Avatar from 'primevue/avatar'
import FileUpload from 'primevue/fileupload'
import ToggleSwitch from 'primevue/toggleswitch'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'

// PanelOS Custom Theme
import './assets/css/main.css'

const PanelOSPreset = definePreset(Aura, {
  semantic: {
    primary: {
      500: '#1a237e',
      600: '#3949ab',
      700: '#303f9f',
    }
  }
})

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(PrimeVue, {
  theme: { preset: PanelOSPreset }
})
app.use(ConfirmationService)
app.use(ToastService)
app.directive('tooltip', Tooltip)

// Register components globally
const components = {
  Button, DataTable, Column, InputText, InputNumber,
  Dropdown, DatePicker, Checkbox, RadioButton, Textarea,
  Dialog, Tag, Badge, Toast, ConfirmDialog, Message,
  Card, Skeleton, ProgressBar, Breadcrumb, Avatar,
  FileUpload, ToggleSwitch, Tabs, TabList, Tab, TabPanels, TabPanel
}
Object.entries(components).forEach(([name, component]) => {
  app.component(name, component)
})

app.mount('#app')
```

---

## 5. Database Setup & Migrations

### Migration Order

```bash
# Run all migrations in order:
php artisan migrate

# The migrations must exist in this order:
# 001 - create_companies_table
# 002 - create_modules_table
# 003 - create_company_modules_table
# 004 - create_users_table
# 005 - create_roles_table
# 006 - create_role_permissions_table
# 007 - create_audit_logs_table
# 008 - create_panel_types_table
# 009 - create_color_master_table
# 010 - create_accessories_master_table
# 011 - create_pricing_rules_table
# 012 - create_quantity_slabs_table
# 013 - create_production_stages_config_table
# 014 - create_machines_table
# 015 - create_number_series_table
# 016 - create_notification_configs_table
# 017 - create_form_configs_table
# 018 - create_form_fields_table
# 019 - create_customers_table
# 020 - create_suppliers_table
# 021 - create_enquiries_table
# 022 - create_quotations_table
# 023 - create_quotation_panel_items_table
# 024 - create_quotation_accessory_items_table
# 025 - create_orders_table
# 026 - create_order_items_table
# 027 - create_order_accessories_table
# 028 - create_production_batches_table
# 029 - create_production_stage_logs_table
# 030 - create_cutting_schedules_table
# 031 - create_cutting_schedule_items_table
# 032 - create_quality_logs_table
# 033 - create_coil_stock_table
# 034 - create_chemical_stock_table
# 035 - create_accessory_stock_table
# 036 - create_inventory_transactions_table
# 037 - create_purchase_orders_table
# 038 - create_purchase_order_items_table
# 039 - create_dispatches_table
# 040 - create_dispatch_items_table
# 041 - create_invoices_table
# 042 - create_invoice_items_table
# 043 - create_payments_table
# 044 - create_credit_debit_notes_table
# 045 - create_custom_field_values_table
# 046 - create_personal_access_tokens_table (Sanctum)
```

### Fresh Migration (Development Only)

```bash
# Drop all tables and re-run
php artisan migrate:fresh --seed

# WARNING: This deletes ALL data
# Only use in development
```

---

## 6. Seeding Default Data

### Run All Seeders

```bash
php artisan db:seed
```

### Individual Seeders

```bash
# Seed modules master list
php artisan db:seed --class=ModulesSeeder

# Seed for Signature PUF (first client)
php artisan db:seed --class=SignatureCompanySeeder

# This runs:
# → Creates company record
# → Creates admin user (Pratik Patel)
# → Enables all modules
# → Creates default roles
# → Seeds panel types (5 products)
# → Seeds color master
# → Seeds accessories master
# → Seeds pricing rules (from actual invoices)
# → Seeds production stages (10 stages)
# → Seeds machines (2 continuous lines)
# → Seeds number series
# → Seeds notification configs
# → Seeds form configs + system fields
# → Seeds default terms & conditions
```

### SignatureCompanySeeder Key Data

```php
// database/seeders/SignatureCompanySeeder.php

class SignatureCompanySeeder extends Seeder
{
    public function run(): void
    {
        // ── Company ──────────────────────────────────
        $company = Company::create([
            'name'          => 'UMA Signature PUF Panel LLP',
            'legal_name'    => 'UMA Signature PUF Panel LLP',
            'subdomain'     => 'signature',
            'gstin'         => '24AAFFU9050M1ZS',
            'pan'           => 'AAFFU9050M',
            'address_line1' => 'Survey No 158/1, At: Dhanora',
            'address_line2' => 'Manglej-Nareshwar Road',
            'city'          => 'Karjan',
            'state'         => 'Gujarat',
            'state_code'    => '24',
            'pincode'       => '391210',
            'phone'         => '9574799722',
            'email'         => 'info@signaturepufpanel.com',
            'website'       => 'https://signaturepufpanel.com',
            'bank_name'     => 'HDFC Bank Ltd',
            'bank_account_no' => '59200000008899',
            'bank_ifsc'     => 'HDFC0003001',
            'bank_branch'   => 'Manjusar, Vadodara',
            'authorized_signatory' => 'Pratik Patel',
            'signatory_designation' => 'Partner',
            'signatory_phone' => '9574799722',
            'quotation_prefix' => 'SCP',
            'invoice_prefix' => 'INV',
            'order_prefix'  => 'ORD',
            'challan_prefix' => 'CH',
            'subscription_plan' => 'growth',
            'subscription_status' => 'active',
            'primary_color' => '#1a237e',
            'secondary_color' => '#f57f17',
        ]);

        // ── Admin User ───────────────────────────────
        User::create([
            'company_id'       => $company->id,
            'name'             => 'Pratik Patel',
            'email'            => 'pratik@signaturepuf.com',
            'phone'            => '9574799722',
            'whatsapp_no'      => '9574799722',
            'password'         => bcrypt('Welcome@2025'),
            'is_company_admin' => true,
            'email_verified_at' => now(),
        ]);

        // ── Panel Types ──────────────────────────────
        $panelTypes = [
            [
                'name'                     => 'Signature Cool Roof',
                'code'                     => 'COOL-ROOF',
                'category'                 => 'roof',
                'core_material'            => 'puf',
                'hsn_code'                 => '39259010',
                'gst_rate'                 => 18.00,
                'available_thicknesses'    => [30, 40, 60, 80, 100, 120],
                'available_densities_puf'  => [48],
                'default_density'          => 48,
                'default_top_surface'      => 'ribbed',
                'default_bottom_surface'   => 'plain',
                'description'              => 'Special cool roof with extra PUF layer, 5-rib profile, CFC-free',
                'sort_order'               => 1,
            ],
            [
                'name'                     => 'Signature Top Roof',
                'code'                     => 'TOP-ROOF',
                'category'                 => 'roof',
                'core_material'            => 'puf',
                'hsn_code'                 => '39259010',
                'gst_rate'                 => 18.00,
                'available_thicknesses'    => [30, 40, 60, 80, 100, 120],
                'available_densities_puf'  => [38, 40, 42],
                'default_density'          => 40,
                'default_top_surface'      => 'ribbed',
                'default_bottom_surface'   => 'plain',
                'description'              => 'PVC-coated galvanized interior for agro/zootechnical use',
                'sort_order'               => 2,
            ],
            [
                'name'                     => 'Signature Tuff Wall',
                'code'                     => 'TUFF-WALL',
                'category'                 => 'wall',
                'core_material'            => 'puf',
                'hsn_code'                 => '39259010',
                'gst_rate'                 => 18.00,
                'available_thicknesses'    => [30, 40, 50, 60, 80, 100],
                'available_densities_puf'  => [38, 40, 42],
                'default_density'          => 40,
                'default_top_surface'      => 'plain',
                'default_bottom_surface'   => 'plain',
                'description'              => 'Micro-ribbed, vertical or horizontal installation, visible fixing',
                'sort_order'               => 3,
            ],
            [
                'name'                     => 'Signature Secret Fix Wall',
                'code'                     => 'SECRET-FIX',
                'category'                 => 'wall',
                'core_material'            => 'puf',
                'hsn_code'                 => '39259010',
                'gst_rate'                 => 18.00,
                'available_thicknesses'    => [50, 100],
                'available_densities_puf'  => [40, 42],
                'default_density'          => 40,
                'default_top_surface'      => 'plain',
                'default_bottom_surface'   => 'plain',
                'description'              => 'Hidden fixing system, 3 external profile options, premium look',
                'sort_order'               => 4,
            ],
            [
                'name'                     => 'Signature Cold Panel',
                'code'                     => 'COLD-PANEL',
                'category'                 => 'cold_room',
                'core_material'            => 'puf',
                'hsn_code'                 => '39259010',
                'gst_rate'                 => 18.00,
                'available_thicknesses'    => [80, 100, 120, 150, 180],
                'available_densities_puf'  => [40, 42, 48],
                'default_density'          => 42,
                'default_top_surface'      => 'plain',
                'default_bottom_surface'   => 'plain',
                'description'              => 'Labyrinth joint, cam-lock option, cold storage specialist',
                'sort_order'               => 5,
            ],
        ];

        foreach ($panelTypes as $data) {
            PanelType::create(array_merge($data, [
                'company_id'            => $company->id,
                'top_skin_materials'    => ['PPGI', 'PPGL', 'GI'],
                'bottom_skin_materials' => ['PPGI', 'PPGL', 'GI'],
                'top_skin_thicknesses'  => [0.30, 0.35, 0.40, 0.45, 0.50, 0.60],
                'bottom_skin_thicknesses' => [0.30, 0.35, 0.40, 0.45, 0.50, 0.60],
                'standard_width_mm'     => 1000,
                'min_production_length_mm' => 2000,
                'delivery_days_standard'   => 14,
                'delivery_days_nonstandard' => 21,
                'warranty_months'          => 12,
                'is_active'                => true,
            ]));
        }

        // ── Number Series ────────────────────────────
        $series = [
            ['document_type' => 'quotation', 'prefix' => 'SCP',  'padding_digits' => 3],
            ['document_type' => 'order',     'prefix' => 'ORD',  'padding_digits' => 3],
            ['document_type' => 'invoice',   'prefix' => 'INV',  'padding_digits' => 3],
            ['document_type' => 'proforma',  'prefix' => 'PFI',  'padding_digits' => 3],
            ['document_type' => 'challan',   'prefix' => 'CH',   'padding_digits' => 3],
            ['document_type' => 'payment',   'prefix' => 'PAY',  'padding_digits' => 4],
            ['document_type' => 'batch',     'prefix' => 'BATCH','padding_digits' => 3],
            ['document_type' => 'dispatch',  'prefix' => 'DISP', 'padding_digits' => 3],
        ];

        foreach ($series as $s) {
            NumberSeries::create(array_merge($s, [
                'company_id'       => $company->id,
                'separator'        => '-',
                'include_year'     => true,
                'current_sequence' => 0,
                'reset_frequency'  => 'yearly',
            ]));
        }

        // ── Default Roles ────────────────────────────
        $roles = [
            [
                'name'        => 'Owner',
                'is_system'   => true,
                'permissions' => $this->getOwnerPermissions(),
            ],
            [
                'name'        => 'Sales Manager',
                'is_system'   => true,
                'permissions' => $this->getSalesPermissions(),
            ],
            [
                'name'        => 'Production Supervisor',
                'is_system'   => true,
                'permissions' => $this->getProductionPermissions(),
            ],
            [
                'name'        => 'Floor Worker',
                'is_system'   => true,
                'permissions' => $this->getFloorWorkerPermissions(),
            ],
            [
                'name'        => 'Accountant',
                'is_system'   => true,
                'permissions' => $this->getAccountantPermissions(),
            ],
            [
                'name'        => 'Dispatch Executive',
                'is_system'   => true,
                'permissions' => $this->getDispatchPermissions(),
            ],
        ];

        foreach ($roles as $role) {
            Role::create(array_merge($role, ['company_id' => $company->id]));
        }

        $this->call([
            SignatureAccessoriesSeeder::class,
            SignatureColorMasterSeeder::class,
            SignaturePricingRulesSeeder::class,
            SignatureProductionStagesSeeder::class,
            SignatureMachinesSeeder::class,
            SignatureNotificationConfigsSeeder::class,
            SignatureFormConfigsSeeder::class,
        ]);
    }
}
```

---

## 7. Environment Variables Reference

### Backend .env — Complete

```env
# ── Application ──────────────────────────────────────
APP_NAME="PanelOS"
APP_ENV=local                     # local | staging | production
APP_KEY=base64:...                # generated by artisan key:generate
APP_DEBUG=true                    # false in production
APP_URL=http://localhost:8000
APP_TIMEZONE=Asia/Kolkata

# ── Database ─────────────────────────────────────────
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=panelos
DB_USERNAME=panelos
DB_PASSWORD=strong_password_here

# ── Redis ─────────────────────────────────────────────
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1

# ── Cache / Session / Queue ───────────────────────────
CACHE_DRIVER=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
QUEUE_CONNECTION=redis

# ── Storage ───────────────────────────────────────────
FILESYSTEM_DISK=local             # local | s3

# AWS S3 (Production)
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=ap-south-1    # Mumbai region
AWS_BUCKET=panelos-storage
AWS_URL=

# ── Email ─────────────────────────────────────────────
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=info@signaturepufpanel.com
MAIL_PASSWORD=app_password_here
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=info@signaturepufpanel.com
MAIL_FROM_NAME="Signature PUF Panel"

# ── WhatsApp API ──────────────────────────────────────
WHATSAPP_PROVIDER=2factor         # 2factor | twilio | wati
WHATSAPP_API_KEY=your_api_key
WHATSAPP_SENDER_NO=+91XXXXXXXXXX
WHATSAPP_ENABLED=false            # true in production

# ── SMS ───────────────────────────────────────────────
SMS_PROVIDER=2factor
SMS_API_KEY=your_sms_key
SMS_ENABLED=false

# ── PDF ───────────────────────────────────────────────
DOMPDF_ENABLE_REMOTE=true
DOMPDF_ENABLE_CSS_FLOAT=true
DOMPDF_ENABLE_HTML5_PARSER=true

# ── Tenancy ───────────────────────────────────────────
TENANCY_CENTRAL_DOMAIN=panelosapp.com
TENANCY_LOCAL_DOMAIN=panelosapp.test

# ── Super Admin ───────────────────────────────────────
SUPERADMIN_DOMAIN=admin.panelosapp.com
SUPERADMIN_IP_WHITELIST=103.x.x.x,106.x.x.x   # comma-separated

# ── E-Invoice (IRP) ───────────────────────────────────
EINVOICE_USERNAME=
EINVOICE_PASSWORD=
EINVOICE_CLIENT_ID=
EINVOICE_CLIENT_SECRET=
EINVOICE_GSTIN=24AAFFU9050M1ZS
EINVOICE_SANDBOX=true             # false in production

# ── Logging ───────────────────────────────────────────
LOG_CHANNEL=stack
LOG_LEVEL=debug                   # debug | info | warning | error

# ── Development Only ──────────────────────────────────
DEBUGBAR_ENABLED=false
TELESCOPE_ENABLED=false
```

### Frontend .env.local

```env
VITE_API_BASE_URL=http://localhost:8000
VITE_APP_NAME=PanelOS
VITE_APP_ENV=development
VITE_TENANT_DOMAIN=panelosapp.test
```

---

## 8. First Company Setup

### After seeding, follow these steps:

```bash
# 1. Start Laravel server
php artisan serve

# 2. Start Vue dev server (new terminal)
cd frontend && npm run dev

# 3. Add to hosts file:
# Windows: C:\Windows\System32\drivers\etc\hosts
# Mac/Linux: /etc/hosts
# Add line:
127.0.0.1   signature.panelosapp.test
127.0.0.1   admin.panelosapp.test
```

### First Login

```
Client Portal:
URL:      http://signature.panelosapp.test:5173
Email:    pratik@signaturepuf.com
Password: Welcome@2025

Super Admin:
URL:      http://admin.panelosapp.test:5173
Email:    superadmin@digitalwebweaver.com
Password: [set in super admin seeder]
```

### Post-Login Checklist

```
□ Login to Super Admin panel
□ Verify Signature PUF company created
□ Verify all 9 modules enabled
□ Login to client portal as Pratik Patel
□ Verify dashboard loads
□ Create test BOQ with 1 panel item
□ Check pricing calculates correctly
□ Download PDF — verify format
□ Create test customer
□ Accept BOQ → verify order created
□ Create production batch
□ Complete all stages
□ Create dispatch
□ Create invoice — verify GST calculated
□ Record payment
□ Check outstanding report
```

---

## 9. Production Deployment

### Server Setup (Ubuntu 22.04 LTS)

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install PHP 8.2
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install php8.2 php8.2-fpm php8.2-mysql php8.2-redis \
     php8.2-gd php8.2-curl php8.2-mbstring php8.2-xml php8.2-zip \
     php8.2-bcmath php8.2-intl php8.2-imagick -y

# Install MySQL 8
sudo apt install mysql-server -y
sudo mysql_secure_installation

# Install Redis
sudo apt install redis-server -y
sudo systemctl enable redis-server

# Install Nginx
sudo apt install nginx -y

# Install Node.js 20
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install nodejs -y

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install Supervisor (for queue workers)
sudo apt install supervisor -y
```

### Deploy Application

```bash
# 1. Clone to server
sudo mkdir -p /var/www/panelos
sudo chown -R $USER:www-data /var/www/panelos
cd /var/www/panelos
git clone https://github.com/your-repo/panelos.git .

# 2. Backend setup
cd backend
composer install --optimize-autoloader --no-dev
cp .env.example .env
# Edit .env with production values
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 3. Frontend build
cd ../frontend
npm install --production
npm run build
# Built files go to: frontend/dist/

# 4. Set permissions
sudo chown -R www-data:www-data /var/www/panelos/backend/storage
sudo chown -R www-data:www-data /var/www/panelos/backend/bootstrap/cache
sudo chmod -R 775 /var/www/panelos/backend/storage
```

### Nginx Configuration

```nginx
# /etc/nginx/sites-available/panelos

# Frontend (Vue SPA) — serves all subdomain requests
server {
    listen 80;
    server_name *.panelosapp.com panelosapp.com;

    # Redirect to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name *.panelosapp.com;

    ssl_certificate     /etc/letsencrypt/live/panelosapp.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/panelosapp.com/privkey.pem;

    # Frontend (Vue build)
    root /var/www/panelos/frontend/dist;
    index index.html;

    # SPA routing
    location / {
        try_files $uri $uri/ /index.html;
    }

    # Proxy API requests to Laravel
    location /api/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # Static files
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}

# Backend (Laravel API)
server {
    listen 8080;
    server_name _;

    root /var/www/panelos/backend/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### SSL Certificate

```bash
# Install Certbot
sudo apt install certbot python3-certbot-nginx -y

# Get wildcard SSL (requires DNS verification)
sudo certbot certonly --manual \
  --preferred-challenges dns \
  -d panelosapp.com \
  -d *.panelosapp.com

# Auto-renew
sudo certbot renew --dry-run
```

### Supervisor — Queue Workers

```ini
; /etc/supervisor/conf.d/panelos-worker.conf

[program:panelos-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/panelos/backend/artisan queue:work redis
        --sleep=3 --tries=3 --timeout=90
        --queue=default,pdf,notifications
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=3
redirect_stderr=true
stdout_logfile=/var/log/panelos/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start panelos-worker:*
sudo supervisorctl status
```

### Cron Jobs

```bash
# Add to crontab (sudo crontab -e -u www-data)
* * * * * cd /var/www/panelos/backend && php artisan schedule:run >> /dev/null 2>&1
```

```php
// app/Console/Kernel.php — scheduled tasks
protected function schedule(Schedule $schedule): void
{
    // Daily: check overdue payments + send alerts
    $schedule->command('panelos:check-overdue')
             ->dailyAt('09:00');

    // Daily: check low stock + send alerts
    $schedule->command('panelos:check-low-stock')
             ->dailyAt('08:00');

    // Daily: check expiring chemicals
    $schedule->command('panelos:check-chemical-expiry')
             ->dailyAt('08:30');

    // Daily: check pending cheques for deposit
    $schedule->command('panelos:check-cheques')
             ->dailyAt('09:30');

    // Weekly: send outstanding statements to customers
    $schedule->command('panelos:send-outstanding-statements')
             ->weeklyOn(1, '10:00');  // Monday 10 AM

    // Yearly: reset number series on April 1
    $schedule->command('panelos:reset-number-series')
             ->yearlyOn(4, 1, '00:01');  // April 1, 12:01 AM
}
```

---

## 10. Server Configuration

### PHP Configuration (php.ini)

```ini
; /etc/php/8.2/fpm/php.ini

; Memory for PDF generation
memory_limit = 256M

; Upload limits
upload_max_filesize = 20M
post_max_size = 25M

; Timeouts
max_execution_time = 120
max_input_time = 120

; OPcache (performance)
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 10000
opcache.revalidate_freq = 60
```

### MySQL Configuration (my.cnf)

```ini
[mysqld]
innodb_buffer_pool_size = 512M
innodb_log_file_size = 128M
max_connections = 200
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
```

### Redis Configuration

```
# /etc/redis/redis.conf
maxmemory 256mb
maxmemory-policy allkeys-lru
save 900 1
save 300 10
```

---

## 11. Maintenance & Updates

### Deploying Updates

```bash
# Deploy script: deploy.sh
#!/bin/bash

echo "Starting deployment..."

cd /var/www/panelos

# Pull latest code
git pull origin main

# Backend updates
cd backend
composer install --optimize-autoloader --no-dev

# Run migrations
php artisan migrate --force

# Clear caches
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart queue workers
sudo supervisorctl restart panelos-worker:*

# Frontend build
cd ../frontend
npm install --production
npm run build

echo "Deployment complete!"
```

```bash
# Make executable
chmod +x deploy.sh

# Run
./deploy.sh
```

### Backup Script

```bash
#!/bin/bash
# backup.sh — run daily via cron

DATE=$(date +%Y-%m-%d)
BACKUP_DIR=/backups/panelos

# Database backup
mysqldump panelos | gzip > $BACKUP_DIR/db_$DATE.sql.gz

# Storage backup
tar -czf $BACKUP_DIR/storage_$DATE.tar.gz \
    /var/www/panelos/backend/storage/app

# Upload to S3
aws s3 cp $BACKUP_DIR/db_$DATE.sql.gz \
    s3://panelos-backups/db/$DATE/

aws s3 cp $BACKUP_DIR/storage_$DATE.tar.gz \
    s3://panelos-backups/storage/$DATE/

# Delete local backups older than 7 days
find $BACKUP_DIR -mtime +7 -delete

echo "Backup complete: $DATE"
```

---

## 12. Troubleshooting

### Common Issues & Fixes

**Issue: Subdomain not resolving**
```bash
# Check nginx config
sudo nginx -t
sudo nginx -s reload

# Check hosts file (local dev)
cat /etc/hosts | grep panelosapp

# Add if missing:
echo "127.0.0.1 signature.panelosapp.test" | sudo tee -a /etc/hosts
```

**Issue: PDF not generating**
```bash
# Check DomPDF logs
tail -f storage/logs/laravel.log | grep -i pdf

# Common fix: GD extension missing
sudo apt install php8.2-gd
sudo service php8.2-fpm restart

# Memory issue: increase in php.ini
memory_limit = 256M
```

**Issue: Queue jobs not processing**
```bash
# Check supervisor status
sudo supervisorctl status panelos-worker:*

# Restart workers
sudo supervisorctl restart panelos-worker:*

# Check for failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all
```

**Issue: Redis connection refused**
```bash
# Check Redis running
redis-cli ping
# Should return: PONG

# Start Redis
sudo service redis start
sudo systemctl enable redis
```

**Issue: 419 CSRF Token Mismatch (API)**
```bash
# API routes should use Sanctum token auth
# Not session-based CSRF
# Check: route is in api.php, not web.php
# Check: Authorization: Bearer {token} header sent
```

**Issue: Tenant not found from subdomain**
```bash
# Check subdomain exists in companies table
mysql -e "SELECT subdomain FROM panelos.companies;"

# Check tenancy config
cat backend/config/tenancy.php | grep central_domains

# Check nginx wildcard subdomain config
grep server_name /etc/nginx/sites-enabled/panelos
```

**Issue: Migrations fail**
```bash
# Check foreign key order
php artisan migrate:status

# Reset and retry (DEV ONLY)
php artisan migrate:fresh

# Production: run specific migration
php artisan migrate --path=/database/migrations/2025_06_01_000001_create_companies_table.php
```

### Log Locations

```
Laravel logs:     backend/storage/logs/laravel.log
Nginx access:     /var/log/nginx/access.log
Nginx errors:     /var/log/nginx/error.log
PHP-FPM:          /var/log/php8.2-fpm.log
Supervisor:       /var/log/panelos/worker.log
MySQL:            /var/log/mysql/error.log
Redis:            /var/log/redis/redis-server.log
```

### Useful Artisan Commands

```bash
# Check application status
php artisan about

# Clear everything
php artisan optimize:clear

# Rebuild all caches
php artisan optimize

# List all routes
php artisan route:list --path=api/v1

# Check queue status
php artisan queue:monitor

# Tail logs
php artisan pail                    # real-time log viewer

# Tinker (interactive PHP)
php artisan tinker
>>> Company::first()
>>> User::where('is_super_admin', true)->get()
```

---

## Development Checklist — Before First Demo

```
□ All migrations run successfully
□ Seeder completes without errors
□ Login works (client + super admin)
□ Dashboard loads with correct data
□ BOQ can be created with multiple panels
□ PDF downloads correctly — matches Signature format
□ Pricing calculates correctly (test against real invoices)
□ Order created from accepted BOQ
□ Production batch creates and stages complete
□ Cutting schedule generates correctly
□ 2000mm rule works (doubled length shows for short panels)
□ Inventory stock updates on production
□ Dispatch challan PDF downloads
□ Invoice with correct GST (CGST+SGST for Gujarat, IGST for other states)
□ Payment recording updates outstanding
□ Super Admin can enable/disable modules
□ Super Admin can toggle field visibility
□ Custom field appears on form and PDF
□ WhatsApp/email notification fires (test mode)
□ Low stock alert triggers
□ All responsive breakpoints work (tablet)
```

---

*PanelOS Setup Guide — Complete*
*Built by Digital Web Weaver — digitalwebweaver.com*
*Version: 1.0 | May 2026*
