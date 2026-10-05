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
        'forecasts'         => ['reports.view', 'reports.view'],
        'analytics'         => ['reports.view', 'reports.view'],
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
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $segment = $request->segment(2);

        if (!$user || !isset(self::MODULES[$segment])) {
            return $next($request);
        }

        [$readKey, $writeKey] = self::MODULES[$segment];
        $isRead = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        $required = $isRead ? $readKey : $writeKey;

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
