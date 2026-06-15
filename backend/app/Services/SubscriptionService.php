<?php

namespace App\Services;

use App\Models\Company;

/**
 * Central subscription state transitions, shared by super-admin manual
 * activation and Razorpay online payment.
 */
class SubscriptionService
{
    /** Activate (or extend) a paid plan for N months. Stacks on remaining time. */
    public function activate(Company $company, string $plan, int $months = 1): Company
    {
        $base = ($company->subscription_ends_at && $company->subscription_ends_at->isFuture())
            ? $company->subscription_ends_at : now();

        $company->update([
            'subscription_plan'    => $plan,
            'subscription_status'  => 'active',
            'is_active'            => true,
            'subscription_ends_at' => $base->copy()->addMonths($months),
        ]);

        return $company->fresh();
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
        return (int) (config('plans.prices')[$plan] ?? 0);
    }
}
