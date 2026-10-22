<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use Illuminate\Database\Seeder;

class DefaultRolesSeeder extends Seeder
{
    public function run(): void
    {
        // Create default company (development). is_active/subscription_plan/
        // subscription_status are guarded on Company — firstOrCreate()'s
        // second array is mass-assignment too, so those three were silently
        // dropped and this landed on the DB defaults ('growth'/'trial')
        // instead of the pro/active plan the seeder intends.
        $company = Company::firstOrCreate(
            ['subdomain' => 'demo'],
            [
                'name' => 'Demo Company',
                'email' => 'demo@panelos.local',
                'phone' => '9876543210',
            ]
        );
        $company->forceFill(['is_active' => true, 'subscription_plan' => 'pro', 'subscription_status' => 'active'])->save();

        // Define 6 default roles
        $roles = [
            [
                'name' => 'Super Admin',
                'description' => 'Full system access',
                'is_system_role' => true,
                'permissions' => ['*'],
            ],
            [
                'name' => 'Company Admin',
                'description' => 'Company-level admin',
                'is_system_role' => true,
                'permissions' => ['*'],
            ],
            [
                'name' => 'Sales Manager',
                'description' => 'Manage quotations and orders',
                'is_system_role' => true,
                'permissions' => ['leads.view', 'leads.manage', 'quotations.view', 'quotations.manage', 'orders.view', 'orders.manage', 'customers.view', 'customers.manage'],
            ],
            [
                'name' => 'Production Manager',
                'description' => 'Manage production batches',
                'is_system_role' => true,
                'permissions' => ['production.view', 'production.manage', 'stock.view', 'stock.manage', 'dispatch.view', 'dispatch.manage', 'procurement.view', 'procurement.manage'],
            ],
            [
                'name' => 'Accounts',
                'description' => 'Manage invoices and payments',
                'is_system_role' => true,
                'permissions' => ['invoices.view', 'invoices.manage', 'payments.view', 'payments.manage', 'reports.view', 'reports.manage'],
            ],
            [
                'name' => 'Viewer',
                'description' => 'View-only access',
                'is_system_role' => true,
                'permissions' => ['leads.view', 'quotations.view', 'orders.view', 'customers.view', 'production.view', 'stock.view', 'invoices.view', 'reports.view'],
            ],
        ];

        foreach ($roles as $roleData) {
            Role::firstOrCreate(
                [
                    'company_id' => $company->id,
                    'name' => $roleData['name'],
                ],
                [
                    'guard_name' => 'web',
                    'permissions' => $roleData['permissions'],
                    'description' => $roleData['description'],
                    'is_system_role' => $roleData['is_system_role'],
                ]
            );
        }

        $this->command->info('Default roles created successfully!');
    }
}
