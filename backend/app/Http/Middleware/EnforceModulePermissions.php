<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Server-side RBAC. Maps the first URL segment to [read key, write key] from
// config/permissions.php. Admins bypass via User::hasPermission. Unmapped
// modules pass through unchanged. Ceiling: module-level only — it doesn't
// distinguish sub-resources inside one module (e.g. all /invoices writes
// need invoices.manage).
class EnforceModulePermissions
{
    // Billing self-service recovery must stay reachable by ANY authenticated
    // user of the tenant (not just settings.manage holders) — EnsureActiveTenant
    // allow-lists these same route names for exactly this reason: a lapsed
    // tenant's non-admin staff must still be able to pay and reactivate.
    private const ROUTE_ALLOW = ['billing.status', 'billing.checkout', 'billing.verify'];

    // Routes whose URL segment doesn't match their actual permission domain —
    // checked by route name BEFORE the segment-based MODULES lookup below.
    // C1: batch creation lives under /orders/{orderId}/batches (segment
    // "orders"), so it was gated on orders.manage instead of the production
    // key it actually needs. C2: dispatch creation/listing-by-batch live
    // under /batches/... (segment "batches"), so they were gated on
    // production.* instead of dispatch.* — letting Production create/see
    // dispatches without dispatch.manage/view, and blocking anyone who held
    // dispatch.* but not production.*.
    private const ROUTE_OVERRIDE = [
        'batches.create'      => 'production.manage',
        'dispatches.create'   => 'dispatch.manage',
        'dispatches.by_batch' => 'dispatch.view',
        'dispatches.plan'     => 'dispatch.view',
        // Stage sign-off is a separate duty from running the line, so it
        // gets its own key rather than riding on production.manage.
        'batches.stages.approve' => 'production.approve',
        'batches.stages.reject'  => 'production.approve',
    ];

    private const MODULES = [
        'quotations'        => ['quotations.view', 'quotations.manage'],
        'orders'            => ['orders.view', 'orders.manage'],
        'customers'         => ['customers.view', 'customers.manage'],
        'leads'             => ['leads.view', 'leads.manage'],
        'batches'           => ['production.view', 'production.manage'],
        'production'        => ['production.view', 'production.manage'],
        'production-stages' => ['production.view', 'production.manage'],
        'quality-control'   => ['production.view', 'production.manage'],
        'dispatches'        => ['dispatch.view', 'dispatch.manage'],
        'invoices'          => ['invoices.view', 'invoices.manage'],
        'payments'          => ['payments.view', 'payments.manage'],
        'stock'             => ['stock.view', 'stock.manage'],
        'procurement'       => ['procurement.view', 'procurement.manage'],
        'purchase-orders'   => ['procurement.view', 'procurement.manage'],
        'suppliers'         => ['procurement.view', 'procurement.manage'],
        'users'             => ['users.manage', 'users.manage'],
        'roles'             => [null, 'settings.manage'],
        'audit-logs'        => ['audit.view', null],
        // MD6: write (forecast generation / analytics snapshots) needs its own
        // key — reports.view is meant to be read-only (e.g. Viewer role has it).
        'forecasts'         => ['reports.view', 'reports.manage'],
        'analytics'         => ['reports.view', 'reports.manage'],
        // C3: was entirely unmapped, so every report route (P&L, balance
        // sheet, cash flow, AR aging, tax, MIS...) passed through with no
        // permission check at all — the single most sensitive data category
        // in the system, left wide open to any authenticated user.
        'reports'           => ['reports.view', 'reports.view'],
        // Master data and company configuration: reads open (forms need them),
        // writes need settings.manage.
        'settings'          => [null, 'settings.manage'],
        'company'           => [null, 'settings.manage'],
        'panel-types'       => [null, 'settings.manage'],
        'accessories'       => [null, 'settings.manage'],
        'material-settings' => [null, 'settings.manage'],
        'document-templates'=> [null, 'settings.manage'],
        'gst'               => [null, 'settings.manage'],
        'sms'               => [null, 'settings.manage'],
        'billing'           => [null, 'settings.manage'],
        // Bulk CSV export of customer/quotation/order/invoice data — same
        // sensitivity class as reports, read-only, no write variant.
        'export'            => ['reports.view', null],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $routeName = $request->route()?->getName();

        if (!$user || in_array($routeName, self::ROUTE_ALLOW, true)) {
            return $next($request);
        }

        if ($routeName !== null && array_key_exists($routeName, self::ROUTE_OVERRIDE)) {
            $required = self::ROUTE_OVERRIDE[$routeName];
        } else {
            $segment = $request->segment(2);
            if (!isset(self::MODULES[$segment])) {
                return $next($request);
            }
            [$readKey, $writeKey] = self::MODULES[$segment];
            $isRead = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
            $required = $isRead ? $readKey : $writeKey;
        }

        if ($required === null || $user->hasPermission($required)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
            'error_code' => 'FORBIDDEN',
            'errors' => ['permission' => [$required]],
        ], 403);
    }
}
