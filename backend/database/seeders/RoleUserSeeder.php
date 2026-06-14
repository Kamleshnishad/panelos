<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RoleUserSeeder extends Seeder
{
    public function run(): void
    {
        // Create demo company
        $company = Company::firstOrCreate(
            ['subdomain' => 'demo'],
            [
                'name' => 'PanelOS Demo Co.',
                'email' => 'demo@panelos.local',
                'phone' => '9876543210',
                'is_active' => true,
                'subscription_plan' => 'pro',
                'subscription_status' => 'active',
            ]
        );

        // Roles
        $roles = [
            ['name' => 'Super Admin',        'description' => 'Full system access',              'permissions' => ['*']],
            ['name' => 'Company Admin',      'description' => 'Company-level admin',              'permissions' => ['companies.manage', 'users.manage', 'roles.manage']],
            ['name' => 'Sales Manager',      'description' => 'Manage quotations and orders',     'permissions' => ['quotations.create', 'quotations.edit', 'orders.view']],
            ['name' => 'Production Manager', 'description' => 'Manage production batches',        'permissions' => ['batches.view', 'batches.update', 'production.complete']],
            ['name' => 'Accounts',           'description' => 'Manage invoices and payments',     'permissions' => ['invoices.view', 'invoices.create', 'payments.manage']],
            ['name' => 'Viewer',             'description' => 'View-only access',                 'permissions' => ['*.view']],
        ];

        $createdRoles = [];
        foreach ($roles as $r) {
            $createdRoles[$r['name']] = Role::firstOrCreate(
                ['company_id' => $company->id, 'name' => $r['name']],
                [
                    'guard_name' => 'web',
                    'permissions' => $r['permissions'],
                    'description' => $r['description'],
                    'is_system_role' => true,
                ]
            );
        }

        // Users for each role
        $users = [
            [
                'name'             => 'Super Admin',
                'email'            => 'superadmin@panelos.local',
                'password'         => 'Admin@123',
                'role'             => 'Super Admin',
                'is_super_admin'   => true,
                'is_company_admin' => true,
            ],
            [
                'name'             => 'Company Admin',
                'email'            => 'admin@panelos.local',
                'password'         => 'Admin@123',
                'role'             => 'Company Admin',
                'is_super_admin'   => false,
                'is_company_admin' => true,
            ],
            [
                'name'     => 'Sales Manager',
                'email'    => 'sales@panelos.local',
                'password' => 'Sales@123',
                'role'     => 'Sales Manager',
                'is_super_admin' => false,
                'is_company_admin' => false,
            ],
            [
                'name'     => 'Production Manager',
                'email'    => 'production@panelos.local',
                'password' => 'Prod@123',
                'role'     => 'Production Manager',
                'is_super_admin' => false,
                'is_company_admin' => false,
            ],
            [
                'name'     => 'Accounts User',
                'email'    => 'accounts@panelos.local',
                'password' => 'Accounts@123',
                'role'     => 'Accounts',
                'is_super_admin' => false,
                'is_company_admin' => false,
            ],
            [
                'name'     => 'Viewer User',
                'email'    => 'viewer@panelos.local',
                'password' => 'Viewer@123',
                'role'     => 'Viewer',
                'is_super_admin' => false,
                'is_company_admin' => false,
            ],
        ];

        foreach ($users as $u) {
            User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'company_id'       => $company->id,
                    'name'             => $u['name'],
                    'password'         => Hash::make($u['password']),
                    'role_id'          => $createdRoles[$u['role']]->id ?? null,
                    'is_super_admin'   => $u['is_super_admin'],
                    'is_company_admin' => $u['is_company_admin'],
                    'is_active'        => true,
                ]
            );

            $this->command->info("✅ {$u['name']} | {$u['email']} | {$u['password']}");
        }

        $this->command->info("\n🏢 Company: {$company->name}");
        $this->command->info('🌐 API Base: http://localhost:8000/api');
    }
}
