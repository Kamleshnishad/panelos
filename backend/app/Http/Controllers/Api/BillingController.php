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
        $prices = config('plans.prices', []);
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
        ]);
        $months = $data['months'] ?? 1;
        $amount = $this->subs->monthlyPrice($data['plan']) * $months;   // rupees
        if ($amount <= 0) {
            return $this->errorResponse([], 'Invalid plan price.', 'BILLING_ERROR', 422);
        }

        try {
            $company = $r->user()->company;
            $order = $this->razorpay->createOrder(
                $amount * 100,                                  // paise
                'sub_' . $company->id . '_' . time(),
                ['company_id' => $company->id, 'plan' => $data['plan'], 'months' => $months],
            );
            return $this->successResponse([
                'order_id'  => $order['id'],
                'amount'    => $order['amount'],
                'currency'  => $order['currency'],
                'key'       => $this->razorpay->keyId(),
                'plan'      => $data['plan'],
                'months'    => $months,
            ], 'Order created');
        } catch (\Throwable $e) {
            Log::error('Razorpay checkout failed', ['error' => $e->getMessage()]);
            return $this->errorResponse(['error' => $e->getMessage()], 'Could not start payment: ' . $e->getMessage(), 'BILLING_ERROR', 400);
        }
    }

    /** Verify the Razorpay Checkout signature and activate the plan. */
    public function verify(Request $r)
    {
        $data = $r->validate([
            'razorpay_order_id'   => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature'  => 'required|string',
            'plan'                => 'required|in:starter,growth,pro,enterprise',
            'months'              => 'required|integer|min:1|max:36',
        ]);

        if (!$this->razorpay->verifyPaymentSignature($data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature'])) {
            return $this->errorResponse([], 'Payment verification failed. If money was deducted, contact support.', 'VERIFY_FAILED', 422);
        }

        $company = $this->subs->activate($r->user()->company, $data['plan'], $data['months'], 'razorpay', $data['razorpay_payment_id'], true, $r->user()->id);
        Log::info('Subscription activated via Razorpay', ['company_id' => $company->id, 'plan' => $data['plan'], 'months' => $data['months'], 'payment_id' => $data['razorpay_payment_id']]);

        return $this->successResponse([
            'subscription_status'  => $company->subscription_status,
            'subscription_plan'    => $company->subscription_plan,
            'subscription_ends_at' => $company->subscription_ends_at,
        ], 'Payment verified — subscription activated!');
    }

    /** Razorpay webhook (public). Activates on payment.captured as a backstop. */
    public function webhook(Request $r)
    {
        $secret = config('services.razorpay.webhook_secret');
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
                if ($company && $company->subscription_status !== 'active') {
                    $this->subs->activate($company, $plan, $months, 'razorpay', 'webhook:' . ($r->input('payload.payment.entity.id') ?? ''));
                    Log::info('Subscription activated via webhook', ['company_id' => $cid, 'plan' => $plan]);
                }
            }
        }
        return response()->json(['ok' => true]);
    }
}
