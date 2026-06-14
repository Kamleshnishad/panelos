<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use Illuminate\Database\Seeder;

class DefaultRolesSeeder extends Seeder
{
    public function run(): void
    {
        // Create default company (development)
        $company = Company::firstOrCreate(
            ['subdomain' => 'demo'],
            [
                'name' => 'Demo Company',
                'email' => 'demo@panelos.local',
                'phone' => '9876543210',
                'is_active' => true,
                'subscription_plan' => 'pro',
                'subscription_status' => 'active',
            ]
        );

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
                'permissions' => ['companies.manage', 'users.manage', 'roles.manage'],
            ],
            [
                'name' => 'Sales Manager',
                'description' => 'Manage quotations and orders',
                'is_system_role' => true,
                'permissions' => ['quotations.create', 'quotations.edit', 'orders.view'],
            ],
            [
                'name' => 'Production Manager',
                'description' => 'Manage production batches',
                'is_system_role' => true,
                'permissions' => ['batches.view', 'batches.update', 'production.complete'],
            ],
            [
                'name' => 'Accounts',
                'description' => 'Manage invoices and payments',
                'is_system_role' => true,
                'permissions' => ['invoices.view', 'invoices.create', 'payments.manage'],
            ],
            [
                'name' => 'Viewer',
                'description' => 'View-only access',
                'is_system_role' => true,
                'permissions' => ['*.view'],
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
