<?php

/**
 * RBAC permission registry. Keys are stored on roles.permissions (array).
 * Admins (is_company_admin / is_super_admin) implicitly have ALL permissions.
 * `costing.view` gates cost/margin fields (unit_cost, base_price, valuation).
 */
return [
    'CRM' => [
        'leads.view'        => 'View leads',
        'leads.manage'      => 'Add / edit leads',
        'leads.own_only'    => 'Restriction: see and work only on leads assigned to them',
        'customers.view'    => 'View customers & 360 profile',
        'customers.manage'  => 'Add / edit customers',
    ],
    'Sales' => [
        'quotations.view'   => 'View quotations / BOQ',
        'quotations.manage' => 'Create / edit quotations, enter rates',
        'orders.view'       => 'View orders',
        'orders.manage'     => 'Manage orders',
    ],
    'Production' => [
        'production.view'   => 'View production (batches/runs/QC)',
        'production.manage' => 'Plan / run production, QC',
        'production.approve'=> 'Sign off production stages',
    ],
    'Inventory' => [
        'inventory.view'    => 'View stock',
        'inventory.manage'  => 'Adjust stock / consumables',
        'stock.view'        => 'View stock (used by default roles)',
        'stock.manage'      => 'Adjust stock (used by default roles)',
        'procurement.view'  => 'View procurement (POs)',
        'procurement.manage'=> 'Create POs / receive goods',
    ],
    'Sales & Finance' => [
        'dispatch.view'     => 'View dispatches',
        'dispatch.manage'   => 'Create dispatches / challan',
        'invoices.view'     => 'View invoices',
        'invoices.manage'   => 'Create / edit invoices',
        'payments.view'     => 'View receivables / payments',
        'payments.manage'   => 'Record payments / reminders',
        'reports.view'      => 'View reports & analytics',
        'reports.manage'    => 'Generate forecasts / analytics snapshots',
        'costing.view'      => 'See cost / margin / valuation (sensitive)',
    ],
    'Administration' => [
        'settings.manage'   => 'Company settings, master data, templates',
        'users.manage'      => 'Manage users & roles',
        'audit.view'        => 'View audit log',
    ],
];
