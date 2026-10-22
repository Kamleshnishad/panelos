<?php

namespace Tests\Feature;

use App\Models\Company;
use Tests\TestCase;

/**
 * Company deliberately excludes subscription_status/subscription_plan/
 * trial_ends_at/subscription_ends_at/is_active from $fillable — its own
 * comment says only SubscriptionService / SuperAdminController may mutate
 * them, via forceFill(). These two endpoints were using plain update()
 * (mass-assignment, subject to the SAME guard) instead, so Eloquent silently
 * dropped every one of those fields with no error: the response looked like
 * success, but the tenant's actual state never changed. Found while writing
 * a C9 regression test, not previously logged as a finding.
 */
class SuperAdminTenantMutationTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    public function test_extend_trial_actually_persists_the_new_trial_end_date(): void
    {
        $superAdmin = $this->createAdminUser();
        $tenant = Company::factory()->create([
            'subscription_status' => 'expired',
            'trial_ends_at' => now()->subDays(5),
        ]);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/admin/companies/{$tenant->id}/extend-trial", ['days' => 14]);

        $response->assertStatus(200);
        $fresh = $tenant->fresh();
        $this->assertSame('trial', $fresh->subscription_status);
        $this->assertTrue($fresh->is_active);
        $this->assertTrue($fresh->trial_ends_at->isFuture());
    }

    public function test_set_active_actually_suspends_a_tenant(): void
    {
        $superAdmin = $this->createAdminUser();
        $tenant = Company::factory()->create(['is_active' => true]);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/admin/companies/{$tenant->id}/set-active", ['is_active' => false]);

        $response->assertStatus(200);
        $this->assertFalse($tenant->fresh()->is_active);
    }
}
