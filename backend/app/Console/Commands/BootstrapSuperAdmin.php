<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * One-time bootstrap for the very first platform super admin. Needed because
 * SuperAdminController::createPlatformAdmin requires already being a super
 * admin to call it — there is no other way to create the first one.
 *
 * Idempotent: safe to re-run. Re-running with the same --email updates the
 * password and re-asserts is_super_admin rather than erroring.
 */
class BootstrapSuperAdmin extends Command
{
    protected $signature = 'admin:bootstrap {--email=} {--password=}';
    protected $description = 'Create or update the platform super admin account';

    public function handle(): int
    {
        $email = $this->option('email');
        $password = $this->option('password');
        if (!$email || !$password) {
            $this->error('Both --email and --password are required.');
            return 1;
        }

        // Super admins still need a company_id (NOT NULL FK) — give them a
        // dedicated platform company rather than attaching to a real tenant.
        $company = Company::firstOrCreate(
            ['subdomain' => 'platform-admin'],
            ['name' => 'PanelOS Platform', 'email' => $email]
        );
        $company->forceFill(['is_active' => true, 'subscription_status' => 'active', 'subscription_plan' => 'enterprise'])->save();

        $admin = User::withoutGlobalScope('tenant')->where('company_id', $company->id)->where('email', $email)->first();
        if ($admin) {
            $admin->update(['password' => Hash::make($password), 'is_active' => true, 'is_company_admin' => true]);
        } else {
            $admin = User::create([
                'company_id' => $company->id,
                'name' => 'Super Admin',
                'email' => $email,
                'password' => Hash::make($password),
                'is_company_admin' => true,
                'is_active' => true,
            ]);
        }
        $admin->forceFill(['is_super_admin' => true])->save();

        $this->info("Super admin ready: {$email}");
        return 0;
    }
}
