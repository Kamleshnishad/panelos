<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Platform-owner panel: manage ALL tenants across the SaaS.
 * Guarded by requireSuperAdmin() — only is_super_admin users.
 * Reads bypass the tenant global scope via withoutGlobalScope('tenant').
 */
class SuperAdminController extends Controller
{
    use ApiResponse;

    private function requireSuperAdmin(Request $r): void
    {
        abort_unless($r->user() && $r->user()->is_super_admin, 403, 'Super admin only.');
    }

    /** Platform overview: counts, MRR estimate, recent signups. */
    public function overview(Request $r)
    {
        $this->requireSuperAdmin($r);

        $companies = Company::withoutGlobalScope('tenant')->withTrashed()->get();
        $byStatus = $companies->groupBy('subscription_status')->map->count();

        // Naive MRR estimate from active paid plans
        $planPrice = config('plans.prices', ['starter' => 2999, 'growth' => 5999, 'pro' => 9999, 'enterprise' => 19999]);
        $mrr = $companies->where('subscription_status', 'active')
            ->sum(fn ($c) => (int) ($planPrice[$c->subscription_plan] ?? 0));

        return $this->successResponse([
            'total_companies'   => $companies->count(),
            'active'            => (int) ($byStatus['active'] ?? 0),
            'trial'             => (int) ($byStatus['trial'] ?? 0),
            'expired'           => (int) ($byStatus['expired'] ?? 0),
            'suspended'         => $companies->where('is_active', false)->count(),
            'mrr_estimate'      => $mrr,
            'total_users'       => User::withoutGlobalScope('tenant')->count(),
            'recent_signups'    => $companies->sortByDesc('created_at')->take(5)->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'status' => $c->subscription_status,
                'plan' => $c->subscription_plan, 'created_at' => $c->created_at,
            ])->values(),
        ], 'Platform overview');
    }

    /** List every tenant with key stats + filters. */
    public function companies(Request $r)
    {
        $this->requireSuperAdmin($r);

        $q = Company::withoutGlobalScope('tenant');
        if ($s = $r->query('status'))  $q->where('subscription_status', $s);
        if ($search = $r->query('search')) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        $rows = $q->orderByDesc('created_at')->get()->map(function ($c) {
            return [
                'id'                  => $c->id,
                'name'                => $c->name,
                'email'               => $c->email,
                'phone'               => $c->phone,
                'subdomain'           => $c->subdomain,
                'subscription_plan'   => $c->subscription_plan,
                'subscription_status' => $c->subscription_status,
                'is_active'           => (bool) $c->is_active,
                'trial_ends_at'       => $c->trial_ends_at,
                'subscription_ends_at'=> $c->subscription_ends_at,
                'users'               => User::withoutGlobalScope('tenant')->where('company_id', $c->id)->count(),
                'created_at'          => $c->created_at,
            ];
        });

        return $this->successResponse($rows, 'Companies retrieved');
    }

    /** Drill-down on one tenant. */
    public function show(Request $r, int $id)
    {
        $this->requireSuperAdmin($r);
        $c = Company::withoutGlobalScope('tenant')->findOrFail($id);

        return $this->successResponse([
            'company'   => $c,
            'users'     => User::withoutGlobalScope('tenant')->where('company_id', $id)->get(['id', 'name', 'email', 'is_company_admin', 'is_active', 'last_login_at']),
            'invoices'  => Invoice::withoutGlobalScope('tenant')->where('company_id', $id)->count(),
        ], 'Company detail');
    }

    /** Activate / extend a subscription (after manual or online payment). */
    public function activate(Request $r, int $id)
    {
        $this->requireSuperAdmin($r);
        $data = $r->validate([
            'plan'        => 'required|in:starter,growth,pro,enterprise',
            'months'      => 'nullable|integer|min:1|max:36',
        ]);
        $c = Company::withoutGlobalScope('tenant')->findOrFail($id);
        $months = $data['months'] ?? 1;

        $base = ($c->subscription_ends_at && $c->subscription_ends_at->isFuture())
            ? $c->subscription_ends_at : now();

        $c->update([
            'subscription_plan'    => $data['plan'],
            'subscription_status'  => 'active',
            'is_active'            => true,
            'subscription_ends_at' => $base->copy()->addMonths($months),
        ]);

        return $this->successResponse($c->fresh(), "Activated {$data['plan']} for {$months} month(s)");
    }

    /** Extend the free trial. */
    public function extendTrial(Request $r, int $id)
    {
        $this->requireSuperAdmin($r);
        $data = $r->validate(['days' => 'required|integer|min:1|max:90']);
        $c = Company::withoutGlobalScope('tenant')->findOrFail($id);

        $base = ($c->trial_ends_at && $c->trial_ends_at->isFuture()) ? $c->trial_ends_at : now();
        $c->update([
            'subscription_status' => 'trial',
            'is_active'           => true,
            'trial_ends_at'       => $base->copy()->addDays($data['days']),
        ]);

        return $this->successResponse($c->fresh(), "Trial extended by {$data['days']} day(s)");
    }

    /** Suspend or un-suspend a tenant. */
    public function setActive(Request $r, int $id)
    {
        $this->requireSuperAdmin($r);
        $data = $r->validate(['is_active' => 'required|boolean']);
        $c = Company::withoutGlobalScope('tenant')->findOrFail($id);
        $c->update(['is_active' => $data['is_active']]);

        return $this->successResponse($c->fresh(), $data['is_active'] ? 'Tenant re-activated' : 'Tenant suspended');
    }
}
