<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Tests\TestCase;

/**
 * Company deliberately excludes subscription_plan/subscription_status/
 * trial_ends_at/is_active from $fillable — only SubscriptionService /
 * SuperAdminController may mutate them, via forceFill(). Two more call sites
 * doing plain mass-assignment instead (silently dropped, no error) were
 * found alongside the C9 investigation:
 *  - EnsureActiveTenant's lazy trial-expiry flip never actually persisted,
 *    so an expired trial was never enforced, on this request or any future
 *    one.
 *  - TenantProvisioningService::provision() (the real signup path) silently
 *    dropped all four fields, landing new tenants on the companies table's
 *    column defaults instead: subscription_plan='growth' (not 'starter')
 *    and trial_ends_at=NULL (no default at all — meaning the trial could
 *    never expire via the lazy flip above, compounding the first bug).
 */
class TenantLifecycleGuardedFieldsTest extends TestCase
{
    public function test_an_expired_trial_is_actually_locked_out_and_the_flip_persists(): void
    {
        $company = Company::factory()->create([
            'subscription_status' => 'trial',
            'trial_ends_at' => now()->subDay(),
            'is_active' => true,
        ]);
        $role = Role::factory()->for($company)->create(['permissions' => ['*']]);
        $user = User::factory()->create(['company_id' => $company->id, 'role_id' => $role->id, 'is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/orders');

        $response->assertStatus(402);
        $response->assertJsonPath('error_code', 'SUBSCRIPTION_INACTIVE');
        $this->assertSame('expired', $company->fresh()->subscription_status);
    }

    public function test_a_new_signup_lands_on_the_starter_plan_with_a_real_trial_end_date(): void
    {
        $result = app(TenantProvisioningService::class)->provision([
            'company_name' => 'New Tenant Co ' . uniqid(),
            'name'         => 'Founder ' . uniqid(),
            'email'        => 'founder' . uniqid() . '@example.com',
            'password'     => 'Password123',
        ]);

        $company = $result['company']->fresh();
        $this->assertSame('starter', $company->subscription_plan);
        $this->assertSame('trial', $company->subscription_status);
        $this->assertTrue($company->is_active);
        $this->assertNotNull($company->trial_ends_at);
        $this->assertTrue($company->trial_ends_at->isFuture());
    }
}
