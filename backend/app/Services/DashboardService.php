<?php

namespace App\Services;

use App\Models\Quotation;
use App\Models\Order;
use App\Models\ProductionBatch;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\CoilStock;
use App\Models\ChemicalStock;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(private ReportingService $reportingService) {}

    public function getDashboard(int $companyId): array
    {
        return [
            'kpis'           => $this->kpis($companyId),
            'pipeline'       => $this->pipeline($companyId),
            'receivables'    => $this->receivables($companyId),
            'alerts'         => $this->alerts($companyId),
            'recent_activity'=> $this->recentActivity($companyId),
        ];
    }

    private function kpis(int $companyId): array
    {
        // Open quotations = draft or sent
        $openQuotations = Quotation::where('company_id', $companyId)
            ->whereIn('status', ['draft', 'sent'])->count();

        // Active orders = pending or in_production
        $activeOrders = Order::where('company_id', $companyId)
            ->whereIn('status', ['pending', 'in_production'])->count();

        // Batches in production = in_progress or qc_pending
        $batchesInProduction = ProductionBatch::where('company_id', $companyId)
            ->whereIn('status', ['in_progress', 'qc_pending'])->count();

        // Pending dispatch = dispatch records still pending
        $pendingDispatch = Dispatch::where('company_id', $companyId)
            ->where('status', 'pending')->count();

        // Outstanding receivables (sum of remaining due on non-paid/cancelled invoices)
        $outstanding = $this->totalOutstanding($companyId);

        // Revenue collected this financial year
        $collectedFy = $this->collectedThisFinancialYear($companyId);

        // ---- Sub-stats (trend / attention indicators) ----
        $quotationsThisWeek = Quotation::where('company_id', $companyId)
            ->where('created_at', '>=', now()->subDays(7))->count();

        $ordersOverdue = Order::where('company_id', $companyId)
            ->whereIn('status', ['pending', 'in_production'])
            ->whereDate('expected_delivery_date', '<', now())->count();

        $batchesOnSchedule = ProductionBatch::where('company_id', $companyId)
            ->where('status', 'in_progress')->count();

        // Ready to ship = QC-passed batches with no dispatch yet
        $dispatchReady = ProductionBatch::where('company_id', $companyId)
            ->where('status', 'qc_passed')->count();

        $overdueInvoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled', 'paid'])
            ->whereDate('due_date', '<', now())->count();

        $collectedLastFy = $this->collectedLastFinancialYear($companyId);
        $yoyPct = $collectedLastFy > 0
            ? round((($collectedFy - $collectedLastFy) / $collectedLastFy) * 100)
            : null;

        return [
            'open_quotations'       => $openQuotations,
            'active_orders'         => $activeOrders,
            'batches_in_production' => $batchesInProduction,
            'pending_dispatch'      => $pendingDispatch,
            'outstanding_amount'    => round($outstanding, 2),
            'collected_fy'          => round($collectedFy, 2),
            // sub-stats
            'quotations_this_week'    => $quotationsThisWeek,
            'orders_overdue'          => $ordersOverdue,
            'batches_on_schedule'     => $batchesOnSchedule,
            'dispatch_ready'          => $dispatchReady,
            'overdue_invoice_count'   => $overdueInvoices,
            'collected_yoy_pct'       => $yoyPct,
        ];
    }

    private function pipeline(int $companyId): array
    {
        // Counts by status for the order-to-cash flow
        return [
            'quotations' => [
                'draft'    => Quotation::where('company_id', $companyId)->where('status', 'draft')->count(),
                'sent'     => Quotation::where('company_id', $companyId)->where('status', 'sent')->count(),
                'accepted' => Quotation::where('company_id', $companyId)->where('status', 'accepted')->count(),
            ],
            'orders' => [
                'pending'       => Order::where('company_id', $companyId)->where('status', 'pending')->count(),
                'in_production' => Order::where('company_id', $companyId)->where('status', 'in_production')->count(),
                'completed'     => Order::where('company_id', $companyId)->where('status', 'completed')->count(),
            ],
            'batches' => [
                'in_progress' => ProductionBatch::where('company_id', $companyId)->where('status', 'in_progress')->count(),
                'qc_pending'  => ProductionBatch::where('company_id', $companyId)->where('status', 'qc_pending')->count(),
                'dispatched'  => ProductionBatch::where('company_id', $companyId)->where('status', 'dispatched')->count(),
            ],
            'invoices' => [
                'sent'     => Invoice::where('company_id', $companyId)->where('status', 'sent')->count(),
                'accepted' => Invoice::where('company_id', $companyId)->where('status', 'accepted')->count(),
                'paid'     => Invoice::where('company_id', $companyId)->where('status', 'paid')->count(),
            ],
        ];
    }

    private function receivables(int $companyId): array
    {
        $ar = $this->reportingService->getAccountsReceivable($companyId);
        return $ar['summary'] ?? [];
    }

    private function alerts(int $companyId): array
    {
        $alerts = [];

        // Low coil stock
        $lowCoils = CoilStock::where('company_id', $companyId)
            ->whereColumn('quantity_in_stock', '<=', 'reorder_level')->count();
        if ($lowCoils > 0) {
            $alerts[] = ['type' => 'low_stock', 'severity' => 'warning', 'message' => "{$lowCoils} coil type(s) below reorder level"];
        }

        // Low chemical stock
        $lowChem = ChemicalStock::where('company_id', $companyId)
            ->whereColumn('quantity_in_stock', '<=', 'reorder_level')->count();
        if ($lowChem > 0) {
            $alerts[] = ['type' => 'low_stock', 'severity' => 'warning', 'message' => "{$lowChem} chemical(s) below reorder level"];
        }

        // Expiring chemicals (next 30 days)
        $expiring = ChemicalStock::where('company_id', $companyId)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(30))
            ->whereDate('expiry_date', '>=', now())
            ->count();
        if ($expiring > 0) {
            $alerts[] = ['type' => 'expiring', 'severity' => 'warning', 'message' => "{$expiring} chemical(s) expiring within 30 days"];
        }

        // Expired chemicals
        $expired = ChemicalStock::where('company_id', $companyId)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now())
            ->where('quantity_in_stock', '>', 0)
            ->count();
        if ($expired > 0) {
            $alerts[] = ['type' => 'expired', 'severity' => 'danger', 'message' => "{$expired} chemical(s) expired with stock on hand"];
        }

        // Overdue invoices
        $overdue = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled', 'paid'])
            ->whereDate('due_date', '<', now())
            ->count();
        if ($overdue > 0) {
            $alerts[] = ['type' => 'overdue', 'severity' => 'danger', 'message' => "{$overdue} invoice(s) overdue for payment"];
        }

        // Expired quotations still open
        $expiredQuotes = Quotation::where('company_id', $companyId)
            ->whereIn('status', ['draft', 'sent'])
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now())
            ->count();
        if ($expiredQuotes > 0) {
            $alerts[] = ['type' => 'quote_expired', 'severity' => 'info', 'message' => "{$expiredQuotes} quotation(s) past validity date"];
        }

        return $alerts;
    }

    private function recentActivity(int $companyId, int $limit = 12): array
    {
        $events = collect();

        Quotation::where('company_id', $companyId)->latest('updated_at')->limit(5)->get()
            ->each(fn ($q) => $events->push([
                'type' => 'quotation', 'ref' => $q->quotation_no, 'status' => $q->status,
                'amount' => $q->total_amount, 'at' => $q->updated_at,
            ]));

        Order::where('company_id', $companyId)->latest('updated_at')->limit(5)->get()
            ->each(fn ($o) => $events->push([
                'type' => 'order', 'ref' => $o->order_no, 'status' => $o->status,
                'amount' => $o->total_amount, 'at' => $o->updated_at,
            ]));

        Dispatch::where('company_id', $companyId)->latest('updated_at')->limit(5)->get()
            ->each(fn ($d) => $events->push([
                'type' => 'dispatch', 'ref' => $d->dispatch_no, 'status' => $d->status,
                'amount' => null, 'at' => $d->updated_at,
            ]));

        Invoice::where('company_id', $companyId)->latest('updated_at')->limit(5)->get()
            ->each(fn ($i) => $events->push([
                'type' => 'invoice', 'ref' => $i->invoice_no, 'status' => $i->status,
                'amount' => $i->total_amount, 'at' => $i->updated_at,
            ]));

        return $events->sortByDesc('at')->take($limit)->values()->all();
    }

    private function totalOutstanding(int $companyId): float
    {
        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled', 'paid'])
            ->get();

        $total = 0;
        foreach ($invoices as $inv) {
            $paid = PaymentTransaction::where('invoice_id', $inv->id)->sum('amount');
            $total += max(0, $inv->total_amount - $paid);
        }
        return $total;
    }

    private function collectedThisFinancialYear(int $companyId): float
    {
        $today  = now();
        $fyYear = $today->month >= 4 ? $today->year : $today->year - 1;
        $fyStart = \Carbon\Carbon::create($fyYear, 4, 1)->startOfDay();

        return (float) PaymentTransaction::where('company_id', $companyId)
            ->where('transaction_date', '>=', $fyStart)
            ->where('payment_method', '!=', 'write_off')
            ->sum('amount');
    }

    private function collectedLastFinancialYear(int $companyId): float
    {
        $today  = now();
        $fyYear = $today->month >= 4 ? $today->year : $today->year - 1;
        $start  = \Carbon\Carbon::create($fyYear - 1, 4, 1)->startOfDay();
        $end    = \Carbon\Carbon::create($fyYear, 3, 31)->endOfDay();

        return (float) PaymentTransaction::where('company_id', $companyId)
            ->whereBetween('transaction_date', [$start, $end])
            ->where('payment_method', '!=', 'write_off')
            ->sum('amount');
    }
}
