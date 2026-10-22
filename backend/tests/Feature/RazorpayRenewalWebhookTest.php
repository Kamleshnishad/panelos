<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPayment;
use Tests\TestCase;

/**
 * C9: the webhook backstop (for when the client's own verify() call never
 * completes) guarded crediting with `$company->subscription_status !== 'active'`.
 * That's wrong for a renewal — a company renewing before its current plan
 * expires is, by definition, still 'active' (status only flips lazily on the
 * next request past subscription_ends_at). So the one scenario this backstop
 * exists for was exactly the one it skipped.
 */
class RazorpayRenewalWebhookTest extends TestCase
{
    private function webhookSignature(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    public function test_renewal_webhook_extends_an_already_active_subscription(): void
    {
        $secret = 'whsec_rzp_test';
        PlatformSetting::current()->update(['razorpay_webhook_secret' => $secret]);

        $company = Company::factory()->create([
            'subscription_status' => 'active',
            'subscription_plan' => 'starter',
            'subscription_ends_at' => now()->addDays(10), // renewing early, well before expiry
        ]);
        $originalEndsAt = $company->subscription_ends_at;

        $payload = json_encode([
            'event' => 'order.paid',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_renewal_test_1',
                        'notes' => [
                            'company_id' => $company->id,
                            'plan' => 'starter',
                            'months' => 1,
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->postJson('/api/webhooks/razorpay', json_decode($payload, true), [
            'X-Razorpay-Signature' => $this->webhookSignature($payload, $secret),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['ok' => true]);

        $this->assertDatabaseHas('subscription_payments', [
            'company_id' => $company->id,
            'reference' => 'pay_renewal_test_1',
        ]);
        $this->assertTrue($company->fresh()->subscription_ends_at->greaterThan($originalEndsAt));
    }

    public function test_webhook_idempotency_still_prevents_double_crediting_the_same_payment(): void
    {
        $secret = 'whsec_rzp_test';
        PlatformSetting::current()->update(['razorpay_webhook_secret' => $secret]);

        $company = Company::factory()->create([
            'subscription_status' => 'active',
            'subscription_plan' => 'starter',
            'subscription_ends_at' => now()->addDays(10),
        ]);

        $payload = json_encode([
            'event' => 'order.paid',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_renewal_test_2',
                        'notes' => ['company_id' => $company->id, 'plan' => 'starter', 'months' => 1],
                    ],
                ],
            ],
        ]);
        $headers = ['X-Razorpay-Signature' => $this->webhookSignature($payload, $secret)];

        $this->postJson('/api/webhooks/razorpay', json_decode($payload, true), $headers)->assertStatus(200);
        $this->postJson('/api/webhooks/razorpay', json_decode($payload, true), $headers)->assertStatus(200);

        $this->assertSame(1, SubscriptionPayment::where('reference', 'pay_renewal_test_2')->count());
    }
}
