<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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

    /** Record a platform-level action against a tenant in the audit log. */
    private function audit(Request $r, int $companyId, string $action, string $label, array $after = []): void
    {
        try {
            AuditLog::create([
                'company_id'     => $companyId,
                'user_id'        => $r->user()->id,
                'user_name'      => $r->user()->name . ' (platform)',
                'action'         => $action,
                'auditable_type' => Company::class,
                'auditable_id'   => $companyId,
                'label'          => $label,
                'after'          => $after,
                'ip'             => $r->ip(),
                'created_at'     => now(),
            ]);
        } catch (\Throwable) { /* never block the action on audit failure */ }
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

        $users = User::withoutGlobalScope('tenant')->where('company_id', $id)->get(['id', 'name', 'email', 'is_company_admin', 'is_active', 'last_login_at']);

        return $this->successResponse([
            'company'   => $c,
            'users'     => $users,
            'usage'     => [
                'users'      => $users->count(),
                'quotations' => Quotation::withoutGlobalScope('tenant')->where('company_id', $id)->count(),
                'orders'     => Order::withoutGlobalScope('tenant')->where('company_id', $id)->count(),
                'invoices'   => Invoice::withoutGlobalScope('tenant')->where('company_id', $id)->count(),
                'last_login' => $users->max('last_login_at'),
            ],
            'recent_actions' => AuditLog::where('company_id', $id)->where('action', 'like', '%subscription%')
                ->orWhere(fn ($q) => $q->where('company_id', $id)->whereIn('action', ['trial_extended', 'tenant_suspended', 'tenant_restored', 'impersonate']))
                ->latest('created_at')->take(10)->get(['action', 'label', 'user_name', 'created_at']),
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

        $c = app(\App\Services\SubscriptionService::class)
            ->activate($c, $data['plan'], $months, 'manual', 'Manual activation by ' . $r->user()->name, true, $r->user()->id);

        $this->audit($r, $c->id, 'subscription_activated', "Activated {$data['plan']} for {$months} month(s)", ['plan' => $data['plan'], 'months' => $months]);
        return $this->successResponse($c, "Activated {$data['plan']} for {$months} month(s)");
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

        $this->audit($r, $c->id, 'trial_extended', "Trial extended by {$data['days']} day(s)", ['days' => $data['days']]);
        return $this->successResponse($c->fresh(), "Trial extended by {$data['days']} day(s)");
    }

    /** Suspend or un-suspend a tenant. */
    public function setActive(Request $r, int $id)
    {
        $this->requireSuperAdmin($r);
        $data = $r->validate(['is_active' => 'required|boolean']);
        $c = Company::withoutGlobalScope('tenant')->findOrFail($id);
        $c->update(['is_active' => $data['is_active']]);

        $this->audit($r, $c->id, $data['is_active'] ? 'tenant_restored' : 'tenant_suspended', $data['is_active'] ? 'Tenant re-activated' : 'Tenant suspended');
        return $this->successResponse($c->fresh(), $data['is_active'] ? 'Tenant re-activated' : 'Tenant suspended');
    }

    /** Tenants whose trial or paid subscription ends within N days (default 7). */
    public function expiring(Request $r)
    {
        $this->requireSuperAdmin($r);
        $days = (int) $r->query('days', 7);
        $until = now()->addDays($days);

        $rows = Company::withoutGlobalScope('tenant')
            ->where('is_active', true)
            ->where(function ($q) use ($until) {
                $q->where(function ($w) use ($until) {
                    $w->where('subscription_status', 'trial')->whereNotNull('trial_ends_at')
                      ->where('trial_ends_at', '<=', $until);
                })->orWhere(function ($w) use ($until) {
                    $w->where('subscription_status', 'active')->whereNotNull('subscription_ends_at')
                      ->where('subscription_ends_at', '<=', $until);
                });
            })
            ->get()
            ->map(function ($c) {
                $end = $c->subscription_status === 'trial' ? $c->trial_ends_at : $c->subscription_ends_at;
                $admin = User::withoutGlobalScope('tenant')->where('company_id', $c->id)->where('is_company_admin', true)->first();
                return [
                    'id'         => $c->id,
                    'name'       => $c->name,
                    'status'     => $c->subscription_status,
                    'plan'       => $c->subscription_plan,
                    'ends_at'    => $end,
                    'days_left'  => $end ? (int) ceil(now()->floatDiffInDays($end, false)) : null,
                    'email'      => $c->email,
                    'phone'      => $c->phone,
                    'admin_name' => $admin?->name,
                ];
            })
            ->sortBy('days_left')->values();

        return $this->successResponse($rows, 'Expiring tenants');
    }

    /** Issue an impersonation token for a tenant's admin (debug/support). Audited. */
    public function impersonate(Request $r, int $id)
    {
        $this->requireSuperAdmin($r);
        $c = Company::withoutGlobalScope('tenant')->findOrFail($id);
        $target = User::withoutGlobalScope('tenant')->where('company_id', $id)
            ->where('is_company_admin', true)->where('is_active', true)->first()
            ?? User::withoutGlobalScope('tenant')->where('company_id', $id)->where('is_active', true)->first();

        if (!$target) {
            return $this->errorResponse([], 'No active user to impersonate in this company.', 'NO_USER', 422);
        }

        $token = $target->createToken('impersonation')->plainTextToken;
        $this->audit($r, $c->id, 'impersonate', "Logged in as {$target->email} of {$c->name}", ['target_user_id' => $target->id]);

        return $this->successResponse([
            'token'        => $token,
            'token_type'   => 'Bearer',
            'company_name' => $c->name,
            'user'         => [
                'id' => $target->id, 'name' => $target->name, 'email' => $target->email,
                'company_id' => $target->company_id,
                'is_super_admin' => false, 'is_company_admin' => $target->is_company_admin,
            ],
        ], "Impersonating {$c->name}");
    }

    /** Platform settings (Razorpay creds + GST seller identity). Secrets masked. */
    public function getSettings(Request $r)
    {
        $this->requireSuperAdmin($r);
        $s = \App\Models\PlatformSetting::current();
        $data = $s->toArray();
        $data['razorpay_key_secret']     = $s->getAttribute('razorpay_key_secret') ? '********' : '';
        $data['razorpay_webhook_secret'] = $s->getAttribute('razorpay_webhook_secret') ? '********' : '';
        $data['secret_is_set']           = !empty($s->getAttribute('razorpay_key_secret'));
        $data['razorpay_ready']          = $s->rzpReady();
        return $this->successResponse($data, 'Platform settings');
    }

    public function updateSettings(Request $r)
    {
        $this->requireSuperAdmin($r);
        $data = $r->validate([
            'razorpay_enabled'         => 'nullable|boolean',
            'razorpay_key_id'          => 'nullable|string|max:100',
            'razorpay_key_secret'      => 'nullable|string|max:150',
            'razorpay_webhook_secret'  => 'nullable|string|max:150',
            'platform_name'            => 'nullable|string|max:150',
            'platform_gstin'           => 'nullable|string|max:20',
            'platform_pan'             => 'nullable|string|max:20',
            'platform_address'         => 'nullable|string|max:255',
            'platform_state'           => 'nullable|string|max:60',
            'platform_state_code'      => 'nullable|string|max:5',
            'platform_email'           => 'nullable|email|max:120',
            'platform_phone'           => 'nullable|string|max:20',
            'platform_sac'             => 'nullable|string|max:12',
        ]);

        // Don't overwrite secrets with the masked placeholder / blanks
        foreach (['razorpay_key_secret', 'razorpay_webhook_secret'] as $sec) {
            if (!array_key_exists($sec, $data) || $data[$sec] === null || $data[$sec] === '' || str_starts_with((string) $data[$sec], '*')) {
                unset($data[$sec]);
            }
        }

        $s = \App\Models\PlatformSetting::current();
        $s->update($data);
        $this->audit($r, $r->user()->company_id, 'platform_settings_updated', 'Platform settings updated');

        return $this->successResponse(['razorpay_ready' => $s->fresh()->rzpReady()], 'Settings saved');
    }

    /** Send a tiny Razorpay test order to confirm credentials work. */
    public function testRazorpay(Request $r)
    {
        $this->requireSuperAdmin($r);
        $rc = new \App\Services\RazorpayClient();
        if (!$rc->isEnabled()) {
            return $this->errorResponse([], 'Razorpay is not enabled or keys are missing. Save valid keys and enable first.', 'RZP_NOT_READY', 422);
        }
        try {
            $order = $rc->createOrder(100, 'test_' . time(), ['test' => true]);   // ₹1 test order
            return $this->successResponse(['order_id' => $order['id']], 'Razorpay credentials work! Test order created.');
        } catch (\Throwable $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'Razorpay test failed: ' . $e->getMessage(), 'RZP_ERROR', 400);
        }
    }

    /** Download a GST subscription tax-invoice PDF for a payment. */
    public function invoicePdf(Request $r, int $paymentId)
    {
        $this->requireSuperAdmin($r);
        $p = \App\Models\SubscriptionPayment::withoutGlobalScope('tenant')->findOrFail($paymentId);
        $company = Company::withoutGlobalScope('tenant')->find($p->company_id);

        $platform = \App\Models\PlatformSetting::current()->billingIdentity();
        $intra = ($company?->state_code ?? '') === $platform['state_code'];
        $html = view('billing.subscription_invoice', [
            'p' => $p, 'company' => $company, 'platform' => $platform,
            'intra' => $intra,
            'words' => \App\Services\InvoiceService::amountInWords((float) $p->total_amount),
        ])->render();

        $pdf = app('dompdf.wrapper');
        $pdf->loadHTML($html);
        $pdf->setPaper('A4');
        return $pdf->download('subscription_' . ($p->invoice_no ?? $p->id) . '.pdf');
    }

    /** Revenue dashboard: real MRR/ARR/ARPU, churn, plan split, recent payments. */
    public function revenue(Request $r)
    {
        $this->requireSuperAdmin($r);
        $prices = config('plans.prices', []);

        $companies = Company::withoutGlobalScope('tenant')->get();
        $activeCos = $companies->where('subscription_status', 'active');
        $mrr  = $activeCos->sum(fn ($c) => (int) ($prices[$c->subscription_plan] ?? 0));
        $arpu = $activeCos->count() ? round($mrr / $activeCos->count()) : 0;

        // Churn this month = went expired/suspended this month / active at month start (approx)
        $churnedThisMonth = $companies->filter(fn ($c) =>
            (($c->subscription_status === 'expired') || !$c->is_active)
            && $c->updated_at && $c->updated_at->isCurrentMonth()
        )->count();

        $payments = \App\Models\SubscriptionPayment::withoutGlobalScope('tenant')
            ->latest('created_at')->take(50)->get();
        $collectedThisMonth = \App\Models\SubscriptionPayment::withoutGlobalScope('tenant')
            ->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('total_amount');

        $byPlan = [];
        foreach ($prices as $plan => $price) {
            $byPlan[] = ['plan' => $plan, 'count' => $activeCos->where('subscription_plan', $plan)->count(), 'price' => $price];
        }

        // Map payments to company names
        $names = $companies->pluck('name', 'id');
        $payRows = $payments->map(fn ($p) => [
            'id' => $p->id, 'company' => $names[$p->company_id] ?? ('#' . $p->company_id),
            'plan' => $p->plan, 'months' => $p->months, 'total' => $p->total_amount,
            'method' => $p->method, 'invoice_no' => $p->invoice_no, 'date' => $p->created_at,
        ]);

        return $this->successResponse([
            'mrr'                  => $mrr,
            'arr'                  => $mrr * 12,
            'arpu'                 => $arpu,
            'active_count'         => $activeCos->count(),
            'churned_this_month'   => $churnedThisMonth,
            'collected_this_month' => round((float) $collectedThisMonth, 2),
            'by_plan'              => $byPlan,
            'payments'             => $payRows,
        ], 'Revenue dashboard');
    }

    /** Signup funnel + conversion + UTM source breakdown. */
    public function funnel(Request $r)
    {
        $this->requireSuperAdmin($r);
        $days = (int) $r->query('days', 30);
        $since = now()->subDays($days)->startOfDay();

        $cos = Company::withoutGlobalScope('tenant')->where('created_at', '>=', $since)->get();
        $signups   = $cos->count();
        $converted = $cos->where('subscription_status', 'active')->count();
        $stillTrial = $cos->where('subscription_status', 'trial')->count();
        $expired   = $cos->where('subscription_status', 'expired')->count();

        // Daily signups series
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i)->format('Y-m-d');
            $series[] = ['date' => now()->subDays($i)->format('d M'), 'count' => $cos->filter(fn ($c) => $c->created_at->format('Y-m-d') === $d)->count()];
        }

        // UTM source breakdown
        $bySource = $cos->groupBy(fn ($c) => $c->utm_source ?: 'direct')->map->count()
            ->map(fn ($count, $src) => ['source' => $src, 'count' => $count])->values();

        return $this->successResponse([
            'period_days'    => $days,
            'signups'        => $signups,
            'converted'      => $converted,
            'still_trial'    => $stillTrial,
            'expired'        => $expired,
            'conversion_pct' => $signups ? round($converted / $signups * 100, 1) : 0,
            'daily'          => $series,
            'by_source'      => $bySource,
        ], 'Signup funnel');
    }

    /** All announcements (super-admin management). */
    public function announcements(Request $r)
    {
        $this->requireSuperAdmin($r);
        return $this->successResponse(\App\Models\PlatformAnnouncement::latest('id')->get(), 'Announcements');
    }

    public function createAnnouncement(Request $r)
    {
        $this->requireSuperAdmin($r);
        $data = $r->validate([
            'message'   => 'required|string|max:500',
            'type'      => 'required|in:info,warning,success',
            'is_active' => 'nullable|boolean',
            'starts_at' => 'nullable|date',
            'ends_at'   => 'nullable|date',
        ]);
        $a = \App\Models\PlatformAnnouncement::create($data);
        $this->audit($r, $r->user()->company_id, 'announcement_created', 'Broadcast: ' . substr($data['message'], 0, 60));
        return $this->successResponse($a, 'Announcement created', 201);
    }

    public function toggleAnnouncement(Request $r, int $id)
    {
        $this->requireSuperAdmin($r);
        $a = \App\Models\PlatformAnnouncement::findOrFail($id);
        $a->update(['is_active' => !$a->is_active]);
        return $this->successResponse($a->fresh(), $a->is_active ? 'Activated' : 'Deactivated');
    }

    public function deleteAnnouncement(Request $r, int $id)
    {
        $this->requireSuperAdmin($r);
        \App\Models\PlatformAnnouncement::findOrFail($id)->delete();
        return $this->successResponse(null, 'Announcement deleted');
    }

    /** List platform admins (is_super_admin users). */
    public function platformAdmins(Request $r)
    {
        $this->requireSuperAdmin($r);
        $admins = User::withoutGlobalScope('tenant')->where('is_super_admin', true)
            ->get(['id', 'name', 'email', 'is_active', 'last_login_at']);
        return $this->successResponse($admins, 'Platform admins');
    }

    /** Create another platform admin. */
    public function createPlatformAdmin(Request $r)
    {
        $this->requireSuperAdmin($r);
        $data = $r->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|max:150|unique:users,email',
            'password' => ['required', \App\Http\Controllers\Api\AuthController::passwordPolicy()],
        ]);

        $admin = User::create([
            'company_id'       => $r->user()->company_id,
            'name'             => $data['name'],
            'email'            => $data['email'],
            'password'         => Hash::make($data['password']),
            'is_super_admin'   => true,
            'is_company_admin' => true,
            'is_active'        => true,
        ]);

        $this->audit($r, $r->user()->company_id, 'platform_admin_created', "Created platform admin {$admin->email}", ['new_admin_id' => $admin->id]);
        return $this->successResponse(['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email], 'Platform admin created', 201);
    }
}
