<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks API access when the tenant's subscription is not in good standing.
 *
 * - Super admins bypass (platform owner).
 * - Lazily flips trial -> expired once trial_ends_at passes.
 * - Returns 402 (Payment Required) JSON with a clear code the frontend turns
 *   into an "upgrade / renew" screen.
 *
 * A few endpoints stay reachable even when expired so the user can recover
 * (see /me, logout, billing, company view). Those are allow-listed by route name.
 */
class EnsureActiveTenant
{
    /** Route names always allowed even for an expired/inactive tenant. */
    private array $allow = [
        'me', 'logout', 'change-password',
        'company.show',            // see own company
        'billing.status', 'billing.plans', 'billing.checkout', 'billing.webhook',
        'notif.settings.show',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user || ($user->is_super_admin ?? false)) {
            return $next($request);
        }

        $company = $user->company;
        if (!$company) {
            return $next($request);   // nothing to enforce
        }

        // Lazy trial-expiry flip
        if ($company->subscription_status === 'trial'
            && $company->trial_ends_at && $company->trial_ends_at->isPast()) {
            $company->update(['subscription_status' => 'expired']);
        }

        $blocked = !$company->is_active || $company->subscription_status === 'expired';

        if ($blocked && !in_array($request->route()?->getName(), $this->allow, true)) {
            $isTrial = $company->trial_ends_at !== null;
            return response()->json([
                'success'    => false,
                'message'    => $isTrial
                    ? 'Your free trial has ended. Please choose a plan to continue.'
                    : 'Your subscription is inactive. Please renew to continue.',
                'error_code' => 'SUBSCRIPTION_INACTIVE',
                'data'       => [
                    'subscription_status' => $company->subscription_status,
                    'is_active'           => (bool) $company->is_active,
                    'trial_ends_at'       => $company->trial_ends_at,
                ],
            ], 402);
        }

        return $next($request);
    }
}
