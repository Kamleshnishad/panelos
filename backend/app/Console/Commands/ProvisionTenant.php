<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\TenantProvisioningService;
use Illuminate\Console\Command;

/**
 * Provisions a real tenant company directly (bypassing the trial signup
 * flow) with a specific plan from the start, and the business details
 * filled in up front rather than left for the Company Admin to enter later.
 *
 * Idempotent on --email: re-running updates the existing company/admin
 * instead of erroring.
 */
class ProvisionTenant extends Command
{
    protected $signature = 'tenant:provision
        {--company= : Company display name}
        {--email= : Company email AND the first admin user\'s login email}
        {--admin-name=Admin : First admin user\'s display name}
        {--password= : First admin user\'s password}
        {--phone=}
        {--address=}
        {--city=}
        {--state=}
        {--state-code=}
        {--pincode=}
        {--plan=enterprise : starter|growth|pro|enterprise}';

    protected $description = 'Create or update a tenant company directly on a given plan, with its first Company Admin user';

    public function handle(TenantProvisioningService $provisioning): int
    {
        $companyName = $this->option('company');
        $email = $this->option('email');
        $password = $this->option('password');
        if (!$companyName || !$email || !$password) {
            $this->error('--company, --email and --password are required.');
            return 1;
        }

        $existing = Company::withoutGlobalScope('tenant')->where('email', $email)->first();
        if ($existing) {
            $company = $existing;
            $admin = \App\Models\User::withoutGlobalScope('tenant')->where('company_id', $company->id)->where('email', $email)->first();
            if ($admin) {
                $admin->update(['password' => \Illuminate\Support\Facades\Hash::make($password), 'is_active' => true]);
            }
        } else {
            $result = $provisioning->provision([
                'company_name' => $companyName,
                'name' => $this->option('admin-name'),
                'email' => $email,
                'password' => $password,
                'phone' => $this->option('phone'),
            ]);
            $company = $result['company'];
        }

        // Business details beyond what provision() sets — all genuinely
        // fillable on Company (no guard involved), safe via plain update().
        $company->update(array_filter([
            'phone' => $this->option('phone'),
            'address_line1' => $this->option('address'),
            'city' => $this->option('city'),
            'state' => $this->option('state'),
            'state_code' => $this->option('state-code'),
            'pincode' => $this->option('pincode'),
        ], fn ($v) => $v !== null));

        // subscription_plan/status/is_active are guarded — see the forceFill
        // note in SubscriptionService::activate().
        $company->forceFill([
            'subscription_plan' => $this->option('plan'),
            'subscription_status' => 'active',
            'is_active' => true,
        ])->save();

        $this->info("Tenant ready: {$company->name} ({$email}) on {$this->option('plan')}");
        return 0;
    }
}
