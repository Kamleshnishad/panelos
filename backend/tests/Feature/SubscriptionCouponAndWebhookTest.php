<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Coupon;
use App\Models\SubscriptionPayment;
use App\Services\SubscriptionService;
use Tests\TestCase;

class SubscriptionCouponAndWebhookTest extends TestCase
{
    public function test_coupon_increments_exactly_once_when_verify_and_webhook_race_for_the_same_payment(): void
    {
        $company = Company::factory()->create(['subscription_status' => 'trial']);
        $coupon = Coupon::create(['code' => 'SAVE10', 'type' => 'percent', 'value' => 10, 'max_uses' => 1, 'used_count' => 0, 'is_active' => true]);

        $service = app(SubscriptionService::class);

        // First caller (e.g. the webhook) wins and credits + increments.
        $service->activate($company, 'starter', 1, 'razorpay', 'pay_race123', true, null, 100, 'SAVE10');
        // Second caller (e.g. verify(), racing the same payment) must be a no-op —
        // idempotency check short-circuits before the coupon block ever runs again.
        $service->activate($company, 'starter', 1, 'razorpay', 'pay_race123', true, null, 100, 'SAVE10');

        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertSame(1, SubscriptionPayment::where('reference', 'pay_race123')->count());
    }

    public function test_coupon_increments_when_only_one_path_fires(): void
    {
        $company = Company::factory()->create(['subscription_status' => 'trial']);
        $coupon = Coupon::create(['code' => 'ONETIME', 'type' => 'flat', 'value' => 50, 'max_uses' => 1, 'used_count' => 0, 'is_active' => true]);

        app(SubscriptionService::class)->activate($company, 'starter', 1, 'razorpay', 'pay_single', true, null, 100, 'ONETIME');

        $this->assertSame(1, $coupon->fresh()->used_count);
    }
}
