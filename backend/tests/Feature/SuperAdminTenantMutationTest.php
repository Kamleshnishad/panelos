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

    /**
     * activate()'s idempotency check matches on $reference alone, with no
     * company scoping (by design — a Razorpay webhook and the client's own
     * verify() call for the SAME payment must dedupe across both paths).
     * SuperAdminController::activate() passed a fixed string ("Manual
     * activation by <admin name>") as that reference — not a unique payment
     * id — so after the first manual activation by a given admin, every
     * later one (for ANY company) silently matched that same row and
     * no-op'd. Reported directly by the user: clicking "Activate" on a
     * second company in the Super Admin panel did nothing.
     */
    public function test_activating_two_different_companies_in_a_row_both_take_effect(): void
    {
        $superAdmin = $this->createAdminUser(['name' => 'Super Admin']);
        $companyA = Company::factory()->create(['subscription_status' => 'trial']);
        $companyB = Company::factory()->create(['subscription_status' => 'trial']);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/admin/companies/{$companyA->id}/activate", ['plan' => 'growth', 'months' => 1])
            ->assertStatus(200);
        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/admin/companies/{$companyB->id}/activate", ['plan' => 'growth', 'months' => 1])
            ->assertStatus(200);

        $this->assertSame('active', $companyA->fresh()->subscription_status);
        $this->assertSame('active', $companyB->fresh()->subscription_status);
    }
}
