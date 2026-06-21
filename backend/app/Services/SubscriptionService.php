<?php

namespace App\Services;

use App\Models\Company;
use App\Models\SubscriptionPayment;

/**
 * Central subscription state transitions, shared by super-admin manual
 * activation and Razorpay online payment. Also records a payment row + a
 * GST-style invoice number for every activation.
 */
class SubscriptionService
{
    /**
     * Activate (or extend) a paid plan for N months. Stacks on remaining time.
     * Records a SubscriptionPayment unless $record is false.
     */
    public function activate(Company $company, string $plan, int $months = 1, string $method = 'manual', ?string $reference = null, bool $record = true, ?int $byUserId = null, ?float $totalOverride = null): Company
    {
        // Atomic: company state + payment record must both land, or neither (CONC-M3).
        return \Illuminate\Support\Facades\DB::transaction(function () use ($company, $plan, $months, $method, $reference, $record, $byUserId, $totalOverride) {
        // Idempotency: a payment already recorded under this reference must never be
        // credited twice (verify() + webhook() can both fire for one payment) — CONC-H2.
        if ($record && $reference && SubscriptionPayment::where('reference', $reference)->exists()) {
            return $company->fresh();
        }

        $base = ($company->subscription_ends_at && $company->subscription_ends_at->isFuture())
            ? $company->subscription_ends_at : now();
        $periodStart = $base->copy();
        $periodEnd   = $base->copy()->addMonths($months);

        $company->update([
            'subscription_plan'    => $plan,
            'subscription_status'  => 'active',
            'is_active'            => true,
            'subscription_ends_at' => $periodEnd,
        ]);

        if ($record) {
            $total   = $totalOverride ?? ($this->monthlyPrice($plan) * $months);   // GST-inclusive
            $rate    = 18.0;
            $taxable = round($total / (1 + $rate / 100), 2);
            $gst     = round($total - $taxable, 2);

            SubscriptionPayment::create([
                'company_id'         => $company->id,
                'plan'               => $plan,
                'months'             => $months,
                'total_amount'       => $total,
                'taxable_amount'     => $taxable,
                'gst_amount'         => $gst,
                'gst_rate'           => $rate,
                'method'             => $method,
                'reference'          => $reference,
                'invoice_no'         => $this->nextInvoiceNo(),
                'period_start'       => $periodStart,
                'period_end'         => $periodEnd,
                'created_by_user_id' => $byUserId,
            ]);
        }

        return $company->fresh();
        });
    }

    /** Sequential platform subscription-invoice number, e.g. PINV-2026-0001. */
    private function nextInvoiceNo(): string
    {
        $year = now()->year;
        $prefix = "PINV-{$year}-";
        $last = SubscriptionPayment::withoutGlobalScope('tenant')
            ->where('invoice_no', 'like', $prefix . '%')
            ->orderByDesc('id')->value('invoice_no');
        $n = $last ? ((int) substr($last, -4)) + 1 : 1;
        return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /** Extend the free trial by N days. */
    public function extendTrial(Company $company, int $days): Company
    {
        $base = ($company->trial_ends_at && $company->trial_ends_at->isFuture())
            ? $company->trial_ends_at : now();

        $company->update([
            'subscription_status' => 'trial',
            'is_active'           => true,
            'trial_ends_at'       => $base->copy()->addDays($days),
        ]);

        return $company->fresh();
    }

    public function monthlyPrice(string $plan): int
    {
        return \App\Models\PlatformSetting::current()->priceFor($plan);
    }
}
