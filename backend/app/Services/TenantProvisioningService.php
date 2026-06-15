<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Provisions a brand-new tenant on self-signup: Company (trial) + default Roles
 * + the first admin User. Runs UNAUTHENTICATED (during public signup), so every
 * company_id is set explicitly — the BaseModel/BelongsToTenant auto-inject hook
 * does NOT fire without an auth user.
 */
class TenantProvisioningService
{
    private const TRIAL_DAYS = 14;

    /**
     * @param array $data ['company_name','name','email','password','phone'?]
     * @return array{company: Company, user: User}
     */
    public function provision(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $company = Company::create([
                'name'                => $data['company_name'],
                'subdomain'           => $this->uniqueSubdomain($data['company_name']),
                'email'               => $data['email'],
                'phone'               => $data['phone'] ?? null,
                'subscription_plan'   => 'starter',
                'subscription_status' => 'trial',
                'trial_ends_at'       => now()->addDays(self::TRIAL_DAYS),
                'is_active'           => true,
                'utm_source'          => $data['utm_source'] ?? null,
                'utm_medium'          => $data['utm_medium'] ?? null,
                'utm_campaign'        => $data['utm_campaign'] ?? null,
                'signup_referrer'     => $data['signup_referrer'] ?? null,
            ]);

            $roles = $this->createDefaultRoles($company->id);

            $user = User::create([
                'company_id'       => $company->id,
                'name'             => $data['name'],
                'email'            => $data['email'],
                'password'         => Hash::make($data['password']),
                'role_id'          => $roles['Company Admin']->id,
                'is_super_admin'   => false,
                'is_company_admin' => true,   // first user owns the tenant
                'is_active'        => true,
            ]);

            return ['company' => $company, 'user' => $user];
        });
    }

    /** Default roles for a new tenant. Admin gets all; others are starting points. */
    private function createDefaultRoles(int $companyId): array
    {
        $defs = [
            ['name' => 'Company Admin',      'description' => 'Full access to this company', 'permissions' => ['*'], 'system' => true],
            ['name' => 'Sales',              'description' => 'Leads, quotations, orders, customers', 'permissions' => [
                'leads.view', 'leads.manage', 'quotations.view', 'quotations.manage', 'orders.view', 'customers.view', 'customers.manage',
            ], 'system' => false],
            ['name' => 'Production',          'description' => 'Production, runs, QC, stock', 'permissions' => [
                'production.view', 'production.manage', 'stock.view',
            ], 'system' => false],
            ['name' => 'Accounts',            'description' => 'Invoices, payments, reports', 'permissions' => [
                'invoices.view', 'invoices.manage', 'payments.view', 'payments.manage', 'reports.view', 'costing.view',
            ], 'system' => false],
            ['name' => 'Viewer',              'description' => 'Read-only', 'permissions' => [
                'leads.view', 'quotations.view', 'orders.view', 'customers.view', 'production.view', 'stock.view', 'invoices.view', 'reports.view',
            ], 'system' => false],
        ];

        $roles = [];
        foreach ($defs as $d) {
            $roles[$d['name']] = Role::create([
                'company_id'     => $companyId,
                'name'           => $d['name'],
                'guard_name'     => 'web',
                'description'    => $d['description'],
                'permissions'    => $d['permissions'],
                'is_system_role' => $d['system'],
            ]);
        }
        return $roles;
    }

    /** Generate a unique subdomain slug from the company name. */
    private function uniqueSubdomain(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') $base = 'tenant';
        $base = substr($base, 0, 40);

        $slug = $base;
        $i = 1;
        while (Company::where('subdomain', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
