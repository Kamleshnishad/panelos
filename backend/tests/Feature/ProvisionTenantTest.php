<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `php artisan tenant:provision` — creates a real tenant directly on a given
 * plan (bypassing the trial flow), with business details filled in up front.
 */
class ProvisionTenantTest extends TestCase
{
    public function test_provisions_a_company_on_the_requested_plan_with_a_working_admin(): void
    {
        $email = 'tenant' . uniqid() . '@example.com';

        Artisan::call('tenant:provision', [
            '--company' => 'Test Tenant Co',
            '--email' => $email,
            '--admin-name' => 'Test Admin',
            '--password' => 'FirstPass123!',
            '--phone' => '+91 90000 00000',
            '--address' => '123 Test Street',
            '--city' => 'Testville',
            '--state' => 'Gujarat',
            '--state-code' => '24',
            '--pincode' => '390001',
            '--plan' => 'enterprise',
        ]);

        $company = Company::withoutGlobalScope('tenant')->where('email', $email)->first();
        $this->assertNotNull($company);
        $this->assertSame('enterprise', $company->subscription_plan);
        $this->assertSame('active', $company->subscription_status);
        $this->assertTrue($company->is_active);
        $this->assertSame('Testville', $company->city);
        $this->assertSame('123 Test Street', $company->address_line1);

        $admin = User::withoutGlobalScope('tenant')->where('email', $email)->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->is_company_admin);
        $this->assertFalse($admin->is_super_admin);
        $this->assertSame(['*'], $admin->role->permissions);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('FirstPass123!', $admin->password));
    }

    public function test_rerunning_with_the_same_email_updates_in_place_instead_of_duplicating(): void
    {
        $email = 'tenant' . uniqid() . '@example.com';
        $args = ['--company' => 'Test Tenant Co', '--email' => $email, '--password' => 'FirstPass123!', '--plan' => 'growth'];

        Artisan::call('tenant:provision', $args);
        Artisan::call('tenant:provision', array_merge($args, ['--password' => 'SecondPass456!', '--plan' => 'enterprise']));

        $this->assertSame(1, Company::withoutGlobalScope('tenant')->where('email', $email)->count());
        $company = Company::withoutGlobalScope('tenant')->where('email', $email)->first();
        $this->assertSame('enterprise', $company->subscription_plan);

        $admin = User::withoutGlobalScope('tenant')->where('email', $email)->first();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('SecondPass456!', $admin->password));
    }
}
