# 10 — UI Design System

> Complete visual design guide for PanelOS.
> Colors, typography, components, layouts, screen designs.
> Built on PrimeVue 4.x + Tailwind CSS utility classes.

---

## Table of Contents

1. [Design Philosophy](#1-design-philosophy)
2. [Color System](#2-color-system)
3. [Typography](#3-typography)
4. [Spacing & Layout](#4-spacing--layout)
5. [Component Library — PrimeVue](#5-component-library--primevue)
6. [Navigation Layout](#6-navigation-layout)
7. [Dashboard Design](#7-dashboard-design)
8. [Data Tables](#8-data-tables)
9. [Form Design](#9-form-design)
10. [Status Badges & Indicators](#10-status-badges--indicators)
11. [Screen-by-Screen Layouts](#11-screen-by-screen-layouts)
12. [PDF Design Standards](#12-pdf-design-standards)
13. [Responsive Design](#13-responsive-design)
14. [Icons](#14-icons)
15. [Loading States & Feedback](#15-loading-states--feedback)

---

## 1. Design Philosophy

### Core Principles
```
1. CLARITY FIRST
   → ERP users need to find information fast
   → No decorative clutter
   → Data-dense but organized

2. INDUSTRIAL FEEL
   → Dark navy + amber/gold palette
   → Conveys trust, precision, manufacturing
   → NOT generic SaaS blue/purple

3. EFFICIENCY
   → Keyboard shortcuts for power users
   → Minimal clicks to common actions
   → Inline editing where appropriate

4. CONFIDENCE
   → Clear status indicators everywhere
   → No ambiguous states
   → Confirm before destructive actions

5. MOBILE AWARE
   → Primary use: desktop (office/factory)
   → Floor worker app: mobile-first
   → Responsive sidebar collapses on tablet
```

### Target Users & Their Needs
```
OWNER (Pratik Patel):
→ Needs: Quick business overview, alerts, financials
→ Uses: Dashboard, Reports, Outstanding
→ Device: Desktop/laptop + mobile

SALES MANAGER:
→ Needs: Fast BOQ creation, order status
→ Uses: BOQ module, Customer list, Orders
→ Device: Desktop primarily

PRODUCTION SUPERVISOR:
→ Needs: What to produce, current status
→ Uses: Production dashboard, Batches, Schedule
→ Device: Desktop at factory office

FLOOR WORKER:
→ Needs: What stage to complete, simple input
→ Uses: Stage tracking (mobile app Phase 2)
→ Device: Mobile/tablet on factory floor

ACCOUNTANT:
→ Needs: Invoices, payments, GST data
→ Uses: Accounts module
→ Device: Desktop
```

---

## 2. Color System

### Primary Palette

```css
:root {
  /* ── PRIMARY — Deep Navy Blue ─────────────────── */
  --color-primary-50:   #e8eaf6;
  --color-primary-100:  #c5cae9;
  --color-primary-200:  #9fa8da;
  --color-primary-300:  #7986cb;
  --color-primary-400:  #5c6bc0;
  --color-primary-500:  #3f51b5;  /* main primary */
  --color-primary-600:  #3949ab;
  --color-primary-700:  #303f9f;
  --color-primary-800:  #283593;
  --color-primary-900:  #1a237e;  /* darkest */
  --color-primary:      #1a237e;

  /* ── ACCENT — Amber/Gold (Manufacturing) ─────── */
  --color-accent-50:    #fff8e1;
  --color-accent-100:   #ffecb3;
  --color-accent-200:   #ffe082;
  --color-accent-300:   #ffd54f;
  --color-accent-400:   #ffca28;
  --color-accent-500:   #ffc107;  /* main accent */
  --color-accent-600:   #ffb300;
  --color-accent-700:   #ffa000;
  --color-accent-800:   #ff8f00;
  --color-accent-900:   #ff6f00;
  --color-accent:       #f57f17;

  /* ── SEMANTIC COLORS ──────────────────────────── */
  --color-success:      #2e7d32;  /* green — paid, completed, pass */
  --color-success-light:#e8f5e9;
  --color-warning:      #e65100;  /* orange — pending, overdue */
  --color-warning-light:#fff3e0;
  --color-danger:       #c62828;  /* red — failed, critical, overdue */
  --color-danger-light: #ffebee;
  --color-info:         #01579b;  /* blue — informational */
  --color-info-light:   #e1f5fe;

  /* ── NEUTRAL / SURFACE ────────────────────────── */
  --color-bg:           #f5f6fa;  /* page background */
  --color-surface:      #ffffff;  /* card/panel background */
  --color-surface-2:    #f8f9fc;  /* subtle secondary surface */
  --color-border:       #e0e0e0;  /* borders, dividers */
  --color-border-dark:  #bdbdbd;

  /* ── TEXT ─────────────────────────────────────── */
  --color-text-primary:   #212121;  /* main text */
  --color-text-secondary: #616161;  /* labels, subtitles */
  --color-text-disabled:  #9e9e9e;  /* disabled states */
  --color-text-inverse:   #ffffff;  /* text on dark bg */
  --color-text-link:      #1565c0;  /* links */

  /* ── SIDEBAR ──────────────────────────────────── */
  --color-sidebar-bg:    #1a237e;   /* deep navy */
  --color-sidebar-text:  #c5cae9;   /* light lavender */
  --color-sidebar-active:#ffffff;   /* white for active */
  --color-sidebar-hover: rgba(255,255,255,0.1);

  /* ── TABLE ────────────────────────────────────── */
  --color-table-header:  #f5f6fa;
  --color-table-row-hover: #e8eaf6;
  --color-table-stripe:  #fafafa;
}
```

### Color Usage Guide

| Color | Use Case | Example |
|---|---|---|
| `--color-primary` (#1a237e) | Buttons, links, active states, headers | Save button, active nav |
| `--color-accent` (#f57f17) | Highlights, important actions, badges | Warning badge, key metric |
| `--color-success` (#2e7d32) | Completed, paid, passed QC | "PAID" badge, ✅ stage done |
| `--color-warning` (#e65100) | Pending, overdue, low stock | "OVERDUE" badge, ⚠️ alert |
| `--color-danger` (#c62828) | Failed, critical, delete | "FAILED" QC, 🗑️ delete |
| `--color-info` (#01579b) | Information, in-progress | "IN PRODUCTION" badge |
| `--color-bg` (#f5f6fa) | Page background | App background |
| `--color-surface` (#ffffff) | Cards, panels, tables | Card background |

### PrimeVue Theme Configuration

```javascript
// main.js
import PrimeVue from 'primevue/config'
import { definePreset } from '@primevue/themes'
import Aura from '@primevue/themes/aura'

const PanelOSTheme = definePreset(Aura, {
  semantic: {
    primary: {
      50:  '{indigo.50}',
      100: '{indigo.100}',
      200: '{indigo.200}',
      300: '{indigo.300}',
      400: '{indigo.400}',
      500: '{indigo.500}',
      600: '{indigo.600}',
      700: '{indigo.700}',
      800: '{indigo.800}',
      900: '{indigo.900}',
      950: '#1a237e'
    }
  },
  components: {
    button: {
      borderRadius: '6px'
    },
    card: {
      borderRadius: '8px',
      shadow: '0 1px 3px rgba(0,0,0,0.1)'
    }
  }
})

app.use(PrimeVue, {
  theme: {
    preset: PanelOSTheme,
    options: { darkModeSelector: '.app-dark' }
  }
})
```

---

## 3. Typography

### Font Stack

```css
/* Primary: Inter (Google Fonts) */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

/* Monospace: For codes, numbers, dimensions */
@import url('https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;500&display=swap');

body {
  font-family: 'Inter', -apple-system, BlinkMacSystemFont,
               'Segoe UI', sans-serif;
  font-size: 14px;
  line-height: 1.5;
  color: var(--color-text-primary);
}
```

### Type Scale

```css
/* Page Titles (h1) */
.text-page-title {
  font-size: 22px;
  font-weight: 600;
  color: var(--color-text-primary);
  letter-spacing: -0.3px;
}

/* Section Headers (h2) */
.text-section-header {
  font-size: 16px;
  font-weight: 600;
  color: var(--color-text-primary);
}

/* Card Titles (h3) */
.text-card-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-primary);
}

/* Body Text */
.text-body {
  font-size: 14px;
  font-weight: 400;
}

/* Label / Small */
.text-label {
  font-size: 12px;
  font-weight: 500;
  color: var(--color-text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

/* Caption */
.text-caption {
  font-size: 11px;
  color: var(--color-text-disabled);
}

/* Monospace — for dimensions, codes */
.text-mono {
  font-family: 'Roboto Mono', monospace;
  font-size: 13px;
}

/* Currency amounts */
.text-amount {
  font-family: 'Roboto Mono', monospace;
  font-size: 14px;
  font-weight: 500;
  text-align: right;
}

/* Large KPI numbers */
.text-kpi {
  font-size: 28px;
  font-weight: 700;
  letter-spacing: -0.5px;
}
```

---

## 4. Spacing & Layout

### Spacing Scale
```css
/* Base: 4px */
--space-1: 4px;
--space-2: 8px;
--space-3: 12px;
--space-4: 16px;
--space-5: 20px;
--space-6: 24px;
--space-8: 32px;
--space-10: 40px;
--space-12: 48px;
--space-16: 64px;
```

### App Layout Structure
```
┌──────────────────────────────────────────────────────────────┐
│  TOPBAR (64px height)                                        │
│  [☰ Menu] [PanelOS Logo]  [Search]  [🔔 Alerts] [User ▼]  │
├──────────┬───────────────────────────────────────────────────┤
│          │                                                    │
│ SIDEBAR  │  MAIN CONTENT AREA                               │
│ (240px)  │                                                    │
│          │  ┌─────────────────────────────────────────────┐  │
│ Nav      │  │  PAGE HEADER                                │  │
│ Items    │  │  Title + Breadcrumb + Action Buttons        │  │
│          │  └─────────────────────────────────────────────┘  │
│          │                                                    │
│          │  ┌─────────────────────────────────────────────┐  │
│          │  │  PAGE CONTENT                               │  │
│          │  │  (Cards, Tables, Forms)                     │  │
│          │  └─────────────────────────────────────────────┘  │
│          │                                                    │
└──────────┴───────────────────────────────────────────────────┘
```

### Content Width
```css
.content-area {
  padding: 24px;
  max-width: 1400px;
  margin: 0 auto;
}

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
}

.card {
  background: var(--color-surface);
  border-radius: 8px;
  border: 1px solid var(--color-border);
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
  padding: 20px;
  margin-bottom: 20px;
}
```

---

## 5. Component Library — PrimeVue

### Button Variants

```vue
<!-- Primary Action -->
<Button label="Save BOQ" severity="primary" icon="pi pi-save" />

<!-- Secondary -->
<Button label="Cancel" severity="secondary" outlined />

<!-- Danger (destructive) -->
<Button label="Delete" severity="danger" icon="pi pi-trash" outlined />

<!-- Success -->
<Button label="Accept Order" severity="success" icon="pi pi-check" />

<!-- Text only (inline links) -->
<Button label="View Details" link />

<!-- Icon only -->
<Button icon="pi pi-pencil" severity="secondary" text rounded />

<!-- Loading state -->
<Button label="Saving..." :loading="isSaving" />
```

### Button Size Guide
```
Standard actions:     size="normal"  (default)
Table row actions:    size="small"
Page primary CTA:     size="large"
```

### Input Components

```vue
<!-- Text input with label -->
<div class="field">
  <label for="project_name" class="field-label">
    Project Name
  </label>
  <InputText
    id="project_name"
    v-model="form.project_name"
    placeholder="Enter project name"
    class="w-full"
  />
</div>

<!-- Number input -->
<InputNumber
  v-model="form.length_mm"
  :min="500"
  :max="14000"
  placeholder="Length in mm"
  suffix=" mm"
  class="w-full"
/>

<!-- Currency input -->
<InputNumber
  v-model="form.rate"
  mode="currency"
  currency="INR"
  locale="en-IN"
  class="w-full"
/>

<!-- Searchable dropdown -->
<Dropdown
  v-model="form.customer_id"
  :options="customers"
  option-label="name"
  option-value="id"
  placeholder="Select Customer"
  filter
  filter-placeholder="Search customers..."
  class="w-full"
/>

<!-- Date picker -->
<DatePicker
  v-model="form.quotation_date"
  date-format="dd-mm-yy"
  show-icon
  class="w-full"
/>
```

### Standard Form Field Layout

```vue
<template>
  <!-- Standard field wrapper — use everywhere -->
  <div class="field mb-4">
    <label class="block text-sm font-medium text-gray-700 mb-1">
      Customer <span class="text-red-500">*</span>
    </label>
    <Dropdown ... class="w-full" />
    <small class="text-red-500 mt-1" v-if="errors.customer_id">
      {{ errors.customer_id[0] }}
    </small>
  </div>
</template>

<style>
.field label {
  font-size: 13px;
  font-weight: 500;
  color: var(--color-text-secondary);
  margin-bottom: 4px;
  display: block;
}
.field .p-inputtext,
.field .p-dropdown {
  width: 100%;
}
</style>
```

### Data Table Standard Config

```vue
<DataTable
  :value="quotations"
  :paginator="true"
  :rows="20"
  :rows-per-page-options="[10, 20, 50]"
  paginator-template="FirstPageLink PrevPageLink PageLinks
                       NextPageLink LastPageLink
                       RowsPerPageDropdown CurrentPageReport"
  current-page-report-template="{first} to {last} of {totalRecords}"
  striped-rows
  hover-effect
  :loading="loading"
  sort-mode="multiple"
  removable-sort
  filter-display="row"
  v-model:filters="filters"
  global-filter-fields="['quotation_no','customer_name','project_name']"
  responsive-layout="scroll"
>
  <template #header>
    <div class="flex justify-between items-center">
      <span class="text-page-title">Quotations</span>
      <div class="flex gap-2">
        <IconField>
          <InputIcon class="pi pi-search" />
          <InputText v-model="searchText" placeholder="Search..." />
        </IconField>
        <Button label="New BOQ" icon="pi pi-plus" @click="createNew" />
      </div>
    </div>
  </template>

  <Column field="quotation_no" header="BOQ No" sortable style="width: 120px">
    <template #body="{ data }">
      <span class="text-mono text-primary font-medium">
        {{ data.quotation_no }}
      </span>
    </template>
  </Column>

  <Column field="customer_name" header="Customer" sortable />

  <Column field="grand_total" header="Amount" sortable style="width: 140px">
    <template #body="{ data }">
      <span class="text-amount">
        ₹{{ formatCurrency(data.grand_total) }}
      </span>
    </template>
  </Column>

  <Column field="status" header="Status" style="width: 120px">
    <template #body="{ data }">
      <StatusBadge :status="data.status" type="quotation" />
    </template>
  </Column>

  <Column header="Actions" style="width: 140px" :exportable="false">
    <template #body="{ data }">
      <div class="flex gap-1">
        <Button icon="pi pi-eye" size="small" text rounded
                @click="viewQuotation(data.id)" v-tooltip="'View'" />
        <Button icon="pi pi-pencil" size="small" text rounded
                @click="editQuotation(data.id)" v-tooltip="'Edit'"
                v-if="data.status === 'draft'" />
        <Button icon="pi pi-file-pdf" size="small" text rounded
                @click="downloadPDF(data.id)" v-tooltip="'Download PDF'" />
        <Button icon="pi pi-ellipsis-v" size="small" text rounded
                @click="showMenu($event, data)" v-tooltip="'More'" />
      </div>
    </template>
  </Column>

</DataTable>
```

---

## 6. Navigation Layout

### Sidebar Structure

```vue
<!-- AppSidebar.vue -->
<template>
  <aside class="sidebar" :class="{ collapsed: isCollapsed }">

    <!-- Logo -->
    <div class="sidebar-logo">
      <img :src="company.logo" :alt="company.name" class="h-8" />
      <span v-if="!isCollapsed" class="sidebar-company-name">
        {{ company.name }}
      </span>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">
      <div v-for="item in navItems" :key="item.code">

        <!-- Module disabled = grayed out -->
        <div v-if="!item.enabled" class="nav-item disabled"
             v-tooltip="'Module not enabled'">
          <i :class="item.icon" />
          <span>{{ item.label }}</span>
        </div>

        <!-- Active module -->
        <RouterLink v-else :to="item.route"
                    class="nav-item"
                    :class="{ active: isActive(item.route) }">
          <i :class="item.icon" />
          <span v-if="!isCollapsed">{{ item.label }}</span>
          <!-- Badge for alerts -->
          <Badge v-if="item.badge" :value="item.badge"
                 severity="danger" class="ml-auto" />
        </RouterLink>

      </div>
    </nav>

    <!-- Bottom: User + Settings -->
    <div class="sidebar-footer">
      <RouterLink to="/settings" class="nav-item">
        <i class="pi pi-cog" />
        <span v-if="!isCollapsed">Settings</span>
      </RouterLink>
      <div class="nav-item user-item" @click="showUserMenu">
        <Avatar :label="userInitials" class="mr-2" />
        <span v-if="!isCollapsed">{{ user.name }}</span>
      </div>
    </div>

  </aside>
</template>
```

### Sidebar CSS

```css
.sidebar {
  width: 240px;
  height: 100vh;
  background: var(--color-primary);     /* Deep Navy */
  display: flex;
  flex-direction: column;
  position: fixed;
  left: 0;
  top: 0;
  transition: width 0.2s ease;
  z-index: 100;
}

.sidebar.collapsed { width: 64px; }

.sidebar-logo {
  padding: 20px 16px;
  border-bottom: 1px solid rgba(255,255,255,0.1);
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 64px;
}

.sidebar-company-name {
  font-size: 13px;
  font-weight: 600;
  color: white;
  white-space: nowrap;
  overflow: hidden;
}

.sidebar-nav {
  flex: 1;
  padding: 12px 0;
  overflow-y: auto;
}

.nav-item {
  display: flex;
  align-items: center;
  padding: 10px 16px;
  color: var(--color-sidebar-text);
  text-decoration: none;
  font-size: 14px;
  font-weight: 500;
  border-radius: 0;
  transition: all 0.15s;
  cursor: pointer;
  white-space: nowrap;
  gap: 12px;
}

.nav-item:hover {
  background: var(--color-sidebar-hover);
  color: white;
}

.nav-item.active {
  background: rgba(255,255,255,0.15);
  color: white;
  border-right: 3px solid var(--color-accent);
}

.nav-item.disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.nav-item .pi {
  font-size: 16px;
  min-width: 16px;
}
```

### Navigation Items

```javascript
const navItems = [
  { code: 'dashboard', label: 'Dashboard',        icon: 'pi pi-home',        route: '/',              enabled: true },
  { code: 'boq',       label: 'BOQ & Quotation',  icon: 'pi pi-file-edit',   route: '/boq',           enabled: modules.boq },
  { code: 'orders',    label: 'Orders',            icon: 'pi pi-shopping-cart',route: '/orders',       enabled: modules.orders },
  { code: 'production',label: 'Production',        icon: 'pi pi-cog',         route: '/production',    enabled: modules.production },
  { code: 'inventory', label: 'Inventory',         icon: 'pi pi-box',         route: '/inventory',     enabled: modules.inventory,
    badge: lowStockCount > 0 ? lowStockCount : null },
  { code: 'dispatch',  label: 'Dispatch',          icon: 'pi pi-truck',       route: '/dispatch',      enabled: modules.dispatch },
  { code: 'accounts',  label: 'Accounts & GST',    icon: 'pi pi-wallet',      route: '/accounts',      enabled: modules.accounts,
    badge: overdueCount > 0 ? overdueCount : null },
  { code: 'crm',       label: 'CRM & Customers',   icon: 'pi pi-users',       route: '/crm',           enabled: modules.crm },
  { code: 'reports',   label: 'Reports',           icon: 'pi pi-chart-bar',   route: '/reports',       enabled: modules.reports },
]
```

### Top Bar

```vue
<template>
  <header class="topbar">
    <!-- Hamburger (mobile) -->
    <Button icon="pi pi-bars" text @click="toggleSidebar"
            class="lg:hidden" />

    <!-- Breadcrumb -->
    <Breadcrumb :model="breadcrumbs" class="flex-1 ml-4" />

    <!-- Right side actions -->
    <div class="topbar-actions">
      <!-- Global search -->
      <IconField>
        <InputIcon class="pi pi-search" />
        <InputText v-model="globalSearch"
                   placeholder="Search orders, customers..."
                   class="w-64" />
      </IconField>

      <!-- Notifications bell -->
      <Button icon="pi pi-bell" text rounded
              @click="toggleNotifications">
        <Badge v-if="unreadCount" :value="unreadCount"
               severity="danger" />
      </Button>

      <!-- User menu -->
      <Button @click="toggleUserMenu" text>
        <Avatar :label="userInitials" size="normal" shape="circle" />
        <span class="ml-2 hidden lg:block">{{ user.name }}</span>
        <i class="pi pi-chevron-down ml-1 text-xs" />
      </Button>
    </div>
  </header>
</template>

<style>
.topbar {
  height: 64px;
  background: white;
  border-bottom: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  padding: 0 24px;
  position: sticky;
  top: 0;
  z-index: 90;
  gap: 16px;
}
</style>
```

---

## 7. Dashboard Design

### KPI Cards Row

```vue
<template>
  <!-- Row 1: Today's KPIs -->
  <div class="grid grid-cols-4 gap-4 mb-6">

    <div class="kpi-card">
      <div class="kpi-icon" style="background: #e8eaf6">
        <i class="pi pi-chart-line" style="color: #1a237e" />
      </div>
      <div class="kpi-content">
        <div class="kpi-label">Today's Production</div>
        <div class="kpi-value">{{ dashboard.today.production_sqm }}</div>
        <div class="kpi-unit">SQM</div>
      </div>
    </div>

    <div class="kpi-card">
      <div class="kpi-icon" style="background: #fff8e1">
        <i class="pi pi-shopping-cart" style="color: #f57f17" />
      </div>
      <div class="kpi-content">
        <div class="kpi-label">Orders In Progress</div>
        <div class="kpi-value">{{ dashboard.pending.orders_in_production }}</div>
        <div class="kpi-unit">orders</div>
      </div>
    </div>

    <div class="kpi-card">
      <div class="kpi-icon" style="background: #e8f5e9">
        <i class="pi pi-truck" style="color: #2e7d32" />
      </div>
      <div class="kpi-content">
        <div class="kpi-label">Ready to Dispatch</div>
        <div class="kpi-value">{{ dashboard.pending.dispatch_ready }}</div>
        <div class="kpi-unit">orders</div>
      </div>
    </div>

    <div class="kpi-card" :class="{ 'kpi-card--alert': overdueCount > 0 }">
      <div class="kpi-icon" style="background: #ffebee">
        <i class="pi pi-exclamation-triangle" style="color: #c62828" />
      </div>
      <div class="kpi-content">
        <div class="kpi-label">Overdue Payments</div>
        <div class="kpi-value">{{ dashboard.pending.overdue_payments }}</div>
        <div class="kpi-unit">invoices</div>
      </div>
    </div>

  </div>
</template>

<style>
.kpi-card {
  background: white;
  border-radius: 8px;
  border: 1px solid var(--color-border);
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.kpi-card--alert {
  border-color: #ef9a9a;
  background: #fff5f5;
}

.kpi-icon {
  width: 48px;
  height: 48px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.kpi-icon .pi { font-size: 20px; }

.kpi-label {
  font-size: 12px;
  color: var(--color-text-secondary);
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 4px;
}

.kpi-value {
  font-size: 28px;
  font-weight: 700;
  color: var(--color-text-primary);
  line-height: 1;
}

.kpi-unit {
  font-size: 12px;
  color: var(--color-text-disabled);
  margin-top: 2px;
}
</style>
```

---

## 8. Data Tables

### Column Types & Formatting

```javascript
// Currency column
formatCurrency(value) {
  return new Intl.NumberFormat('en-IN', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0
  }).format(value)
  // Output: "4,22,235"
}

// With ₹ symbol
formatCurrencyFull(value) {
  return '₹' + formatCurrency(value)
}

// Date column
formatDate(value) {
  return dayjs(value).format('DD-MMM-YYYY')
  // Output: "10-Jun-2025"
}

// SQM column
formatSQM(value) {
  return parseFloat(value).toFixed(2) + ' SQM'
  // Output: "312.93 SQM"
}

// Dimensions column (mm)
formatDimension(value) {
  return value.toLocaleString('en-IN') + ' mm'
}
```

### Empty State

```vue
<template #empty>
  <div class="empty-state">
    <i class="pi pi-inbox text-4xl text-gray-300 mb-3" />
    <p class="text-gray-500 font-medium">No quotations found</p>
    <p class="text-gray-400 text-sm mb-4">
      Create your first BOQ to get started
    </p>
    <Button label="Create BOQ" icon="pi pi-plus"
            @click="$router.push('/boq/create')" />
  </div>
</template>
```

---

## 9. Form Design

### Two-Column Form Layout

```vue
<template>
  <div class="card">
    <h2 class="text-section-header mb-6">Create Quotation</h2>

    <div class="grid grid-cols-2 gap-x-6 gap-y-4">

      <!-- Full width field -->
      <div class="col-span-2 field">
        <label>Customer *</label>
        <Dropdown v-model="form.customer_id" ... class="w-full" />
      </div>

      <!-- Half width fields -->
      <div class="field">
        <label>Project Name</label>
        <InputText v-model="form.project_name" class="w-full" />
      </div>
      <div class="field">
        <label>Project Location</label>
        <InputText v-model="form.project_location" class="w-full" />
      </div>

      <!-- Quality grade — radio group -->
      <div class="col-span-2 field">
        <label>Quality Grade *</label>
        <div class="flex gap-6 mt-1">
          <div v-for="grade in ['high','medium','standard']" :key="grade"
               class="flex items-center gap-2">
            <RadioButton v-model="form.quality_grade"
                         :value="grade" :input-id="grade" />
            <label :for="grade" class="cursor-pointer capitalize">
              {{ grade }}
            </label>
          </div>
        </div>
      </div>

    </div>

    <!-- Form Actions -->
    <div class="flex justify-end gap-3 mt-6 pt-4 border-t">
      <Button label="Cancel" severity="secondary" outlined
              @click="$router.back()" />
      <Button label="Save Draft" severity="secondary"
              icon="pi pi-save" @click="saveDraft" />
      <Button label="Save & Preview" severity="primary"
              icon="pi pi-eye" :loading="saving"
              @click="saveAndPreview" />
    </div>
  </div>
</template>
```

---

## 10. Status Badges & Indicators

### StatusBadge Component

```vue
<!-- components/Common/StatusBadge.vue -->
<template>
  <Tag :value="config.label" :severity="config.severity"
       :class="['status-badge', `status-${status}`]"
       :icon="config.icon" />
</template>

<script setup>
const props = defineProps({
  status: String,
  type: {
    type: String,
    default: 'quotation' // quotation, order, production, invoice, dispatch
  }
})

const statusConfigs = {
  quotation: {
    draft:     { label: 'Draft',    severity: 'secondary', icon: 'pi pi-pencil' },
    sent:      { label: 'Sent',     severity: 'info',      icon: 'pi pi-send' },
    accepted:  { label: 'Accepted', severity: 'success',   icon: 'pi pi-check' },
    rejected:  { label: 'Rejected', severity: 'danger',    icon: 'pi pi-times' },
    revised:   { label: 'Revised',  severity: 'warning',   icon: 'pi pi-refresh' },
    expired:   { label: 'Expired',  severity: 'danger',    icon: 'pi pi-clock' },
  },
  order: {
    confirmed:             { label: 'Confirmed',        severity: 'info' },
    in_production:         { label: 'In Production',    severity: 'warning' },
    partially_produced:    { label: 'Partly Produced',  severity: 'warning' },
    produced:              { label: 'Produced',         severity: 'success' },
    partially_dispatched:  { label: 'Partly Dispatched',severity: 'info' },
    dispatched:            { label: 'Dispatched',       severity: 'success' },
    delivered:             { label: 'Delivered',        severity: 'success' },
    cancelled:             { label: 'Cancelled',        severity: 'danger' },
  },
  production: {
    scheduled:    { label: 'Scheduled',   severity: 'secondary' },
    in_progress:  { label: 'In Progress', severity: 'warning' },
    completed:    { label: 'Completed',   severity: 'success' },
    on_hold:      { label: 'On Hold',     severity: 'danger' },
    cancelled:    { label: 'Cancelled',   severity: 'danger' },
  },
  invoice: {
    draft:           { label: 'Draft',          severity: 'secondary' },
    sent:            { label: 'Sent',           severity: 'info' },
    partially_paid:  { label: 'Partial',        severity: 'warning' },
    paid:            { label: 'Paid',           severity: 'success' },
    overdue:         { label: 'Overdue',        severity: 'danger' },
    cancelled:       { label: 'Cancelled',      severity: 'danger' },
  },
  qc: {
    pass:    { label: 'Pass',    severity: 'success' },
    fail:    { label: 'Fail',    severity: 'danger' },
    marginal:{ label: 'Marginal',severity: 'warning' },
    rework:  { label: 'Rework',  severity: 'warning' },
  }
}

const config = computed(() =>
  statusConfigs[props.type]?.[props.status] ||
  { label: props.status, severity: 'secondary' }
)
</script>
```

### Alert Banners

```vue
<!-- Low stock alert -->
<Message severity="warn" :closable="false">
  <template #icon><i class="pi pi-exclamation-triangle" /></template>
  <strong>Low Stock Alert:</strong>
  PPGI 0.40mm Off White — only 45 kg remaining (min: 100 kg)
  <Button label="Order Now" size="small" link class="ml-2" />
</Message>

<!-- Production completed -->
<Message severity="success" :closable="true">
  BATCH-2025-045 completed. 85 panels produced, 2 rejected.
</Message>

<!-- Overdue payment -->
<Message severity="error" :closable="false">
  <strong>2 invoices overdue</strong> — Total outstanding: ₹4,06,193
  <Button label="View" size="small" link class="ml-2" />
</Message>
```

---

## 11. Screen-by-Screen Layouts

### BOQ Create Screen Layout

```
┌─────────────────────────────────────────────────────────────────┐
│ ← BOQ List    Create New BOQ                  [Save Draft] [Preview] │
├─────────────────────────────────────────────────────────────────┤
│ CARD: Header Info                                               │
│ ┌──────────┬─────────────────┬───────────┬──────────────────┐  │
│ │ SCP-028  │ Customer* [▼]  │ [+Add]   │ Date: 10-Jun-2025 │  │
│ ├──────────┴─────────────────┴──────────┴──────────────────┘  │
│ │ Project Name [        ] │ Project Location [           ]    │  │
│ │ Quality: ● High ○ Medium ○ Standard                        │  │
│ └───────────────────────────────────────────────────────────┘  │
├─────────────────────────────────────────────────────────────────┤
│ CARD: Panel Items                                               │
│ ┌──────────────────────────────────────────────────────────┐   │
│ │ TABLE HEADER: Panel | Thick | Den | Top | Bot | L | W | N │   │
│ │              | SQM | Rate | Amt | HSN | Action            │   │
│ ├──────────────────────────────────────────────────────────┤   │
│ │ ROW 1: [▼ Panel] [▼ Thick] ... [3660] [1000] [60] ...   │   │
│ │ ROW 2: [▼ Panel] ...                                      │   │
│ │                                         [+ Add PUF Row]   │   │
│ └──────────────────────────────────────────────────────────┘   │
├─────────────────────────────────────────────────────────────────┤
│ CARD: Accessories                                               │
│ [+ Add Accessory]                                               │
│ [Accessory▼] [MTR] [55] [₹150] [₹8,250] [🗑]                  │
│ [Accessory▼] [MTR] [60] [₹350] [₹21,000] [🗑]                 │
├─────────────────────────────────────────────────────────────────┤
│ CARD: Summary                                                   │
│ ┌─────────────────────────────────────────────────────────┐    │
│ │ Panel Subtotal:  ₹3,28,577  Transport: ○Extra ●Fixed [₹]│    │
│ │ Accessories:     ₹  29,250  GST @18%:         ₹  64,409 │    │
│ │ Subtotal:        ₹3,57,827  GRAND TOTAL:      ₹4,22,236 │    │
│ │ Discount [0]%:   ₹       0  Advance:          ₹1,50,000 │    │
│ │ Taxable:         ₹3,57,827  Balance:          ₹2,72,236 │    │
│ └─────────────────────────────────────────────────────────┘    │
│                                                                  │
│         [Cancel] [Save Draft] [Preview PDF] [Send to Customer]  │
└─────────────────────────────────────────────────────────────────┘
```

### Production Dashboard Layout

```
┌──────────────────────────────────────────────────────────────────┐
│ Production                          Date: 10-Jun-2025  [+ Batch] │
├───────────┬──────────────┬────────────────┬──────────────────────┤
│ SCHEDULED │ IN PROGRESS  │ COMPLETED TODAY│ REJECTION RATE       │
│ 3 batches │ 2 batches    │ 1 batch        │ 2.3%                 │
├───────────┴──────────────┴────────────────┴──────────────────────┤
│                                                                    │
│ ACTIVE BATCHES                                                     │
│ ┌────────────────────────────────────────────────────────────┐    │
│ │ ● BATCH-045 | Line 1 | Day Shift | Kwality → Roof 30MM    │    │
│ │ Progress: ██████████████░░░░  6/8 stages                  │    │
│ │ Stage: Quality Check (In Progress)                         │    │
│ │ Operator: Ramesh Kumar                                     │    │
│ │                          [View Details] [Mark Stage Done]  │    │
│ ├────────────────────────────────────────────────────────────┤    │
│ │ ● BATCH-046 | Line 2 | Day Shift | Ideal Agri → Wall 50MM │    │
│ │ Progress: ██████████████████  7/8 stages                  │    │
│ │ Stage: Packing (Pending)                                   │    │
│ └────────────────────────────────────────────────────────────┘    │
│                                                                    │
│ ALERTS                                                             │
│ ⚠️ Low Stock: PPGI 0.40mm OW — 45kg remaining                    │
│ ⚠️ BATCH-043: 3 panels failed QC — action needed                  │
└────────────────────────────────────────────────────────────────────┘
```

---

## 12. PDF Design Standards

### PDF Generation: Laravel DomPDF

```php
// app/Services/PDFService.php

class PDFService
{
    public function generateQuotationPDF(Quotation $quotation): string
    {
        $data = [
            'company'    => $quotation->company,
            'quotation'  => $quotation,
            'items'      => $quotation->panelItems,
            'accessories'=> $quotation->accessoryItems,
            'custom_fields' => $this->getCustomFieldValues($quotation),
        ];

        $pdf = Pdf::loadView('pdfs.quotation', $data)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont'  => 'DejaVu Sans',
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultMediaType' => 'print',
                'dpi' => 150,
            ]);

        $filename = "quotation_{$quotation->quotation_no}.pdf";
        $path = "quotations/{$quotation->company_id}/{$filename}";

        Storage::put($path, $pdf->output());
        return $path;
    }
}
```

### PDF Blade Template Structure

```blade
{{-- resources/views/pdfs/quotation.blade.php --}}
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    /* ─── Base ─── */
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: 'DejaVu Sans', sans-serif;
      font-size: 10px;
      color: #212121;
      line-height: 1.4;
    }

    /* ─── Header ─── */
    .header { border-bottom: 2px solid #1a237e; padding-bottom: 10px; }
    .company-name { font-size: 13px; font-weight: bold; color: #1a237e; }
    .doc-title { font-size: 16px; font-weight: bold; text-align: center; }
    .date { font-size: 11px; text-align: right; }

    /* ─── Buyer Block ─── */
    .buyer-section {
      border: 1px solid #e0e0e0;
      padding: 8px;
      margin: 8px 0;
    }

    /* ─── Items Table ─── */
    .items-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    .items-table th {
      background: #f5f6fa;
      border: 1px solid #e0e0e0;
      padding: 6px;
      font-size: 9px;
      font-weight: 600;
      text-align: center;
    }
    .items-table td {
      border: 1px solid #e0e0e0;
      padding: 5px;
      font-size: 9px;
      vertical-align: top;
    }
    .item-description { font-weight: 600; font-size: 9px; }
    .item-spec { color: #616161; font-size: 8px; }
    .hsn-code { font-size: 8px; color: #1a237e; font-weight: 600; }

    /* ─── Size Sub-table ─── */
    .size-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
    .size-table td { border: 1px solid #e0e0e0; padding: 2px 4px;
                     font-size: 8px; text-align: center; }
    .size-table th { background: #fafafa; font-size: 8px;
                     border: 1px solid #e0e0e0; padding: 2px 4px; }

    /* ─── Summary ─── */
    .summary-table { width: 100%; margin-left: auto; max-width: 280px; }
    .summary-row td { padding: 3px 6px; font-size: 10px; }
    .summary-total td { font-weight: bold; font-size: 11px;
                        border-top: 2px solid #1a237e; }
    .amount { text-align: right; font-family: monospace; }

    /* ─── Grand Total Highlight ─── */
    .grand-total-row { background: #f5f6fa; }
    .grand-total-row td { font-size: 12px; font-weight: bold;
                          color: #1a237e; padding: 5px 6px; }

    /* ─── Footer ─── */
    .page-footer { position: fixed; bottom: 0; width: 100%;
                   font-size: 8px; color: #9e9e9e;
                   border-top: 1px solid #e0e0e0; padding-top: 4px; }
  </style>
</head>
<body>

{{-- PAGE 1: MAIN INVOICE --}}
<div class="header">
  <table width="100%"><tr>
    <td width="30%">
      @if($company->logo)
        <img src="{{ storage_path('app/' . $company->logo) }}" height="40">
      @endif
    </td>
    <td width="40%" style="text-align:center">
      <div class="doc-title">PROFORMA INVOICE</div>
    </td>
    <td width="30%" style="text-align:right">
      <div>{{ $company->email }}</div>
      <div>{{ $company->alternate_email }}</div>
    </td>
  </tr></table>
</div>

{{-- Company info --}}
<div style="margin: 6px 0">
  <strong class="company-name">{{ $company->legal_name }}</strong><br>
  {{ $company->address_line1 }}, {{ $company->address_line2 }},
  TA: {{ $company->city }}, VADODARA: {{ $company->pincode }}, {{ $company->state }}<br>
  <strong>GSTIN: {{ $company->gstin }}</strong>
  &nbsp;&nbsp;&nbsp;
  <strong>{{ $company->certifications ?? 'AN ISO 9001:2015 COMPANY' }}</strong>
</div>

{{-- Buyer block --}}
{{-- Items table --}}
{{-- Summary --}}
{{-- ... (full implementation in code) --}}

</body>
</html>
```

---

## 13. Responsive Design

### Breakpoints
```css
/* Mobile:  < 640px  (floor worker app - Phase 2) */
/* Tablet:  640-1024px */
/* Desktop: > 1024px (primary target) */

/* PanelOS is desktop-first */
/* Sidebar collapses on tablet */
/* Forms stack vertically on tablet */
/* Mobile: floor worker app (Phase 2) */
```

### Tailwind Classes Used
```
Container:   max-w-7xl mx-auto px-6
Grid:        grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4
Flex:        flex items-center justify-between gap-4
Spacing:     p-4 m-4 mb-6 mt-4 gap-4
Text:        text-sm font-medium text-gray-600
Colors:      bg-white border border-gray-200 rounded-lg shadow-sm
```

---

## 14. Icons

### PrimeIcons Used

```
Navigation:
pi-home              → Dashboard
pi-file-edit         → BOQ
pi-shopping-cart     → Orders
pi-cog               → Production / Settings
pi-box               → Inventory
pi-truck             → Dispatch
pi-wallet            → Accounts
pi-users             → CRM
pi-chart-bar         → Reports
pi-bell              → Notifications

Actions:
pi-plus              → Create/Add
pi-pencil            → Edit
pi-trash             → Delete
pi-eye               → View
pi-send              → Send
pi-check             → Accept/Approve
pi-times             → Reject/Cancel
pi-refresh           → Revise/Reload
pi-copy              → Duplicate
pi-download          → Download
pi-file-pdf          → PDF
pi-print             → Print

Status:
pi-clock             → Pending/Scheduled
pi-spin pi-spinner   → Loading
pi-exclamation-triangle → Warning
pi-info-circle       → Info
pi-check-circle      → Success
pi-times-circle      → Error/Failed
```

---

## 15. Loading States & Feedback

### Loading Indicators

```vue
<!-- Table loading -->
<DataTable :loading="loading">
  <template #loading>
    <div class="flex items-center justify-center p-8">
      <i class="pi pi-spin pi-spinner text-2xl text-primary mr-3" />
      <span>Loading quotations...</span>
    </div>
  </template>
</DataTable>

<!-- Button loading -->
<Button label="Saving..." :loading="saving" />

<!-- Page loading skeleton -->
<Skeleton width="100%" height="60px" class="mb-3" />
<Skeleton width="80%" height="20px" class="mb-2" />
<Skeleton width="60%" height="20px" />
```

### Toast Notifications

```javascript
// composables/useToast.js
import { useToast } from 'primevue/usetoast'

export function useAppToast() {
  const toast = useToast()

  return {
    success: (message, detail) => toast.add({
      severity: 'success',
      summary: message,
      detail,
      life: 3000
    }),

    error: (message, detail) => toast.add({
      severity: 'error',
      summary: message,
      detail,
      life: 5000
    }),

    warn: (message, detail) => toast.add({
      severity: 'warn',
      summary: message,
      detail,
      life: 4000
    }),

    info: (message, detail) => toast.add({
      severity: 'info',
      summary: message,
      detail,
      life: 3000
    }),
  }
}

// Usage:
const { success, error } = useAppToast()
success('BOQ Saved', 'SCP-2025-028 saved as draft')
error('Save Failed', 'Customer is required')
```

### Confirm Dialogs

```javascript
// Before destructive actions
import { useConfirm } from 'primevue/useconfirm'

const confirm = useConfirm()

function deleteQuotation(id) {
  confirm.require({
    message: 'Are you sure you want to delete this quotation? This cannot be undone.',
    header: 'Delete Confirmation',
    icon: 'pi pi-exclamation-triangle',
    rejectLabel: 'Cancel',
    acceptLabel: 'Delete',
    acceptClass: 'p-button-danger',
    accept: async () => {
      await api.delete(`/quotations/${id}`)
      success('Deleted', 'Quotation deleted successfully')
    }
  })
}
```

---

*Next: [11_SETUP_GUIDE.md](11_SETUP_GUIDE.md)*
