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
            // subscription_plan/status, trial_ends_at and is_active are
            // deliberately excluded from Company::$fillable (see
            // SubscriptionService::activate()'s note) — Company::create() is
            // mass-assignment too, so those four keys were silently dropped
            // here and every new signup landed on the DB column defaults
            // instead: subscription_plan='growth' (not 'starter'),
            // trial_ends_at=NULL (no default at all) — meaning the trial
            // never naturally expires, since EnsureActiveTenant's lazy-expiry
            // flip requires a non-null trial_ends_at in the past. Every
            // self-signup silently got unlimited free access on the growth
            // plan. Fixed by forceFill()-ing the guarded fields after create().
            $company = Company::create([
                'name'                => $data['company_name'],
                'subdomain'           => $this->uniqueSubdomain($data['company_name']),
                'email'               => $data['email'],
                'phone'               => $data['phone'] ?? null,
                'utm_source'          => $data['utm_source'] ?? null,
                'utm_medium'          => $data['utm_medium'] ?? null,
                'utm_campaign'        => $data['utm_campaign'] ?? null,
                'signup_referrer'     => $data['signup_referrer'] ?? null,
            ]);
            $company->forceFill([
                'subscription_plan'   => 'enterprise',
                'subscription_status' => 'trial',
                'trial_ends_at'       => now()->addDays(self::TRIAL_DAYS),
                'is_active'           => true,
            ])->save();

            $roles = $this->createDefaultRoles($company->id);
            $this->seedOperatingDefaults($company->id);

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

    /**
     * The master data a tenant cannot run production without.
     *
     * The BOM engine resolves materials by EXACT strings — chemicals by category
     * 'Polyol' and 'Isocyanate', consumables by 'film' and 'tape', coil by
     * panel_type_id. A new tenant who named them anything else, or simply had no
     * rows yet, hit "Insufficient stock" on their first run with nothing to
     * explain why. Seeding the rows at zero removes that dead end: they only
     * have to book quantities in.
     *
     * Panel types are deliberately NOT seeded — those are the tenant's own
     * product range and prices, and a wrong default is worse than none.
     */
    private function seedOperatingDefaults(int $companyId): void
    {
        $stages = ['Raw Material Inspection', 'Sheet Cutting', 'Foam Injection', 'Curing', 'Quality Check', 'Packaging'];
        foreach ($stages as $i => $name) {
            \App\Models\ProductionStage::create([
                'company_id' => $companyId,
                'name'       => $name,
                'sequence'   => $i + 1,
                'is_active'  => true,
                'requires_approval' => false,   // the tenant opts in per stage
            ]);
        }

        foreach ([['Polyol', 'Polyol', 'kg'], ['Isocyanate (MDI)', 'Isocyanate', 'kg']] as [$name, $category, $unit]) {
            \App\Models\ChemicalStock::create([
                'company_id' => $companyId, 'chemical_id' => null,
                'name' => $name, 'category' => $category, 'unit' => $unit,
                'quantity_in_stock' => 0, 'reorder_level' => 0,
            ]);
        }

        foreach ([['Protective Film', 'film', 'sqm'], ['Sealant Tape', 'tape', 'm']] as [$name, $category, $unit]) {
            \App\Models\ConsumableStock::create([
                'company_id' => $companyId,
                'name' => $name, 'category' => $category, 'unit' => $unit,
                'quantity_in_stock' => 0, 'reorder_level' => 0, 'unit_cost' => 0,
            ]);
        }

        // Standard 18% exclusive GST, the same rate the quotation engine uses.
        // TaxService would auto-provision this on the first invoice anyway; doing
        // it here means the tenant can see and change it before invoicing.
        \App\Models\TaxConfiguration::create([
            'company_id' => $companyId,
            'tax_type' => 'exclusive',
            'default_tax_rate' => \App\Services\TaxService::DEFAULT_GST_RATE,
            'is_active' => true,
        ]);

        \App\Models\MaterialSetting::create(['company_id' => $companyId]);
    }

    /** Default roles for a new tenant. Admin gets all; others are starting points. */
    private function createDefaultRoles(int $companyId): array
    {
        $defs = [
            ['name' => 'Company Admin',      'description' => 'Full access to this company', 'permissions' => ['*'], 'system' => true],
            ['name' => 'Sales',              'description' => 'Leads, quotations, orders, customers', 'permissions' => [
                'leads.view', 'leads.manage', 'quotations.view', 'quotations.manage', 'orders.view', 'orders.manage', 'customers.view', 'customers.manage',
            ], 'system' => false],
            ['name' => 'Production',          'description' => 'Production, runs, QC, stock, dispatch, procurement', 'permissions' => [
                'production.view', 'production.manage', 'stock.view', 'stock.manage',
                'dispatch.view', 'dispatch.manage', 'procurement.view', 'procurement.manage',
            ], 'system' => false],
            ['name' => 'Accounts',            'description' => 'Invoices, payments, reports', 'permissions' => [
                'invoices.view', 'invoices.manage', 'payments.view', 'payments.manage', 'reports.view', 'reports.manage', 'costing.view',
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
