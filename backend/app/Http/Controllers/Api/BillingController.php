<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\RazorpayClient;
use App\Services\SubscriptionService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Tenant subscription billing via Razorpay.
 * Pluggable: when Razorpay keys aren't configured, checkout returns a clear
 * "billing not configured" message and the manual super-admin activation path
 * remains the way to activate a tenant.
 */
class BillingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private RazorpayClient $razorpay,
        private SubscriptionService $subs,
    ) {}

    /** Plans + prices + current company subscription status. */
    public function status(Request $r)
    {
        $c = $r->user()->company;
        $prices = \App\Models\PlatformSetting::current()->planPrices();
        $plans = [];
        foreach ($prices as $key => $price) {
            $plans[] = [
                'key'      => $key,
                'name'     => ucfirst($key),
                'price'    => $price,
                'limits'   => config("plans.limits.{$key}", []),
            ];
        }
        return $this->successResponse([
            'online_billing'       => $this->razorpay->isEnabled(),
            'razorpay_key'         => $this->razorpay->isEnabled() ? $this->razorpay->keyId() : null,
            'company_name'         => $c?->name,
            'company_email'        => $c?->email,
            'current_plan'         => $c?->subscription_plan,
            'subscription_status'  => $c?->subscription_status,
            'trial_ends_at'        => $c?->trial_ends_at,
            'subscription_ends_at' => $c?->subscription_ends_at,
            'plans'                => $plans,
        ], 'Billing status');
    }

    /** Create a Razorpay order for a plan × months. Frontend opens Checkout with it. */
    public function checkout(Request $r)
    {
        if (!$this->razorpay->isEnabled()) {
            return $this->errorResponse([], 'Online payment is not configured. Please contact us to activate your plan.', 'BILLING_DISABLED', 422);
        }
        $data = $r->validate([
            'plan'   => 'required|in:starter,growth,pro,enterprise',
            'months' => 'nullable|integer|min:1|max:36',
            'coupon' => 'nullable|string|max:40',
        ]);
        $months = $data['months'] ?? 1;
        $amount = $this->subs->monthlyPrice($data['plan']) * $months;   // rupees
        if ($amount <= 0) {
            return $this->errorResponse([], 'Invalid plan price.', 'BILLING_ERROR', 422);
        }

        // Apply coupon if provided
        $couponCode = null; $discounted = $amount;
        if (!empty($data['coupon'])) {
            $coupon = \App\Models\Coupon::usable($data['coupon']);
            if (!$coupon) return $this->errorResponse([], 'Invalid or expired coupon.', 'COUPON_INVALID', 422);
            $discounted = $coupon->apply($amount);
            $couponCode = $coupon->code;
        }

        try {
            $company = $r->user()->company;
            $order = $this->razorpay->createOrder(
                (int) round($discounted * 100),                 // paise
                'sub_' . $company->id . '_' . time(),
                ['company_id' => $company->id, 'plan' => $data['plan'], 'months' => $months, 'coupon' => $couponCode, 'amount' => $discounted],
            );
            return $this->successResponse([
                'order_id'  => $order['id'],
                'amount'    => $order['amount'],
                'currency'  => $order['currency'],
                'key'       => $this->razorpay->keyId(),
                'plan'      => $data['plan'],
                'months'    => $months,
                'coupon'    => $couponCode,
                'base'      => $amount,
                'payable'   => $discounted,
            ], 'Order created');
        } catch (\Throwable $e) {
            Log::error('Razorpay checkout failed', ['error' => $e->getMessage()]);
            return $this->errorResponse(['error' => $e->getMessage()], 'Could not start payment: ' . $e->getMessage(), 'BILLING_ERROR', 400);
        }
    }

    /** Verify the Razorpay Checkout signature and activate the plan. */
    public function verify(Request $r)
    {
        // Only the Razorpay handshake fields are trusted from the client. Plan,
        // months, amount and company are read back from the order on Razorpay's
        // side — never from the request — so a user can't pay Starter and ask for
        // Enterprise (SEC-C1).
        $data = $r->validate([
            'razorpay_order_id'   => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature'  => 'required|string',
        ]);

        if (!$this->razorpay->verifyPaymentSignature($data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature'])) {
            return $this->errorResponse([], 'Payment verification failed. If money was deducted, contact support.', 'VERIFY_FAILED', 422);
        }

        // Authoritative source of truth: re-fetch the order from Razorpay.
        try {
            $order = $this->razorpay->fetchOrder($data['razorpay_order_id']);
        } catch (\Throwable $e) {
            Log::error('Razorpay order fetch failed in verify', ['error' => $e->getMessage()]);
            return $this->errorResponse(['error' => $e->getMessage()], 'Could not confirm the payment with Razorpay. Contact support.', 'VERIFY_FAILED', 422);
        }

        $isPaid = ($order['status'] ?? null) === 'paid'
            || (int) ($order['amount_paid'] ?? 0) >= (int) ($order['amount'] ?? 0);
        if (!$isPaid) {
            return $this->errorResponse([], 'Payment not captured yet. If money was deducted, contact support.', 'VERIFY_FAILED', 422);
        }

        $notes   = $order['notes'] ?? [];
        $plan    = $notes['plan'] ?? null;
        $months  = (int) ($notes['months'] ?? 0);
        $company = $r->user()->company;

        if (!$plan || !in_array($plan, ['starter', 'growth', 'pro', 'enterprise'], true)
            || $months < 1 || (int) ($notes['company_id'] ?? 0) !== (int) $company->id) {
            return $this->errorResponse([], 'Payment could not be matched to your account/plan. Contact support.', 'VERIFY_FAILED', 422);
        }

        // Idempotency: if this payment was already processed (e.g. the webhook beat
        // us), return current state without crediting again (CONC-H2).
        if (\App\Models\SubscriptionPayment::where('reference', $data['razorpay_payment_id'])->exists()) {
            return $this->successResponse([
                'subscription_status'  => $company->subscription_status,
                'subscription_plan'    => $company->subscription_plan,
                'subscription_ends_at' => $company->subscription_ends_at,
            ], 'Subscription already activated for this payment.');
        }

        $amountPaid = (float) ((($order['amount_paid'] ?? $order['amount'] ?? 0)) / 100); // paise → rupees
        if (!empty($notes['coupon'])) {
            $coupon = \App\Models\Coupon::usable($notes['coupon']);
            if ($coupon) $coupon->increment('used_count');
        }

        $company = $this->subs->activate($company, $plan, $months, 'razorpay', $data['razorpay_payment_id'], true, $r->user()->id, $amountPaid);
        Log::info('Subscription activated via Razorpay', ['company_id' => $company->id, 'plan' => $plan, 'months' => $months, 'payment_id' => $data['razorpay_payment_id']]);

        return $this->successResponse([
            'subscription_status'  => $company->subscription_status,
            'subscription_plan'    => $company->subscription_plan,
            'subscription_ends_at' => $company->subscription_ends_at,
        ], 'Payment verified — subscription activated!');
    }

    /** Razorpay webhook (public). Activates on payment.captured as a backstop. */
    public function webhook(Request $r)
    {
        $secret = \App\Models\PlatformSetting::current()->rzpWebhookSecret();
        $sig    = $r->header('X-Razorpay-Signature', '');
        if (!$secret || !$this->razorpay->verifyWebhookSignature($r->getContent(), $sig, $secret)) {
            return response()->json(['ok' => false], 400);
        }

        $event = $r->input('event');
        if ($event === 'payment.captured' || $event === 'order.paid') {
            $notes = data_get($r->all(), 'payload.payment.entity.notes')
                  ?? data_get($r->all(), 'payload.order.entity.notes', []);
            $cid    = $notes['company_id'] ?? null;
            $plan   = $notes['plan'] ?? null;
            $months = (int) ($notes['months'] ?? 1);
            if ($cid && $plan) {
                $company = Company::withoutGlobalScope('tenant')->find($cid);
                // Same reference key as verify() (raw payment id) so activate()'s
                // idempotency guard dedupes the two paths (CONC-H2).
                $paymentId = $r->input('payload.payment.entity.id') ?? '';
                if ($company && $company->subscription_status !== 'active') {
                    $this->subs->activate($company, $plan, $months, 'razorpay', $paymentId);
                    Log::info('Subscription activated via webhook', ['company_id' => $cid, 'plan' => $plan]);
                }
            }
        }
        return response()->json(['ok' => true]);
    }
}
