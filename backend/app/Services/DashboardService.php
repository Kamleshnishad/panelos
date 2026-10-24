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
use Illuminate\Support\Facades\Cache;

class DashboardService
{
    public function __construct(private ReportingService $reportingService) {}

    public function getDashboard(int $companyId): array
    {
        // Cache 60s per company — the dashboard runs ~25+ aggregate queries and is
        // polled often; a short TTL cuts DB load with no user-visible staleness (SCALE-H1).
        return Cache::remember("dashboard:{$companyId}", 60, fn () => [
            'kpis'           => $this->kpis($companyId),
            'pipeline'       => $this->pipeline($companyId),
            'receivables'    => $this->receivables($companyId),
            'alerts'         => $this->alerts($companyId),
            'recent_activity'=> $this->recentActivity($companyId),
            'analytics'      => $this->analytics($companyId),
            'setup'          => $this->setupChecklist($companyId),
        ]);
    }

    /**
     * BI section: 12-month trends plus FY breakdowns by customer, region,
     * lead source, payment method, and operational status mixes.
     */
    private function analytics(int $companyId): array
    {
        $from    = now()->startOfMonth()->subMonths(11);
        $months  = collect(range(0, 11))->map(fn ($i) => $from->copy()->addMonths($i)->format('Y-m'));
        $fyStart = $this->fyStart();

        $series = function ($q, $dateCol, $valCol) use ($months, $from) {
            $sums = $this->monthlySum($q, $dateCol, $valCol, $from);
            return $months->map(fn ($m) => round((float) ($sums[$m] ?? 0), 2))->all();
        };

        $invoicedQ = Invoice::where('company_id', $companyId)->whereNotIn('status', ['draft', 'cancelled']);
        $paidQ     = PaymentTransaction::where('company_id', $companyId)->where('payment_method', '!=', 'write_off');
        // Columns qualified: this query is reused with a customers join below.
        $ordersQ   = Order::where('orders.company_id', $companyId)->where('orders.status', '!=', 'cancelled');

        $trend = [
            'months'     => $months->all(),
            'invoiced'   => $series(clone $invoicedQ, 'invoice_date', 'total_amount'),
            'collected'  => $series(clone $paidQ, 'transaction_date', 'amount'),
            'booked'     => $series(clone $ordersQ, 'order_date', 'total_amount'),
            'quoted'     => $series(Quotation::where('company_id', $companyId), 'created_at', 'total_amount'),
            'produced'   => $series(ProductionBatch::where('company_id', $companyId)->whereNotNull('completed_at'), 'completed_at', 'completed_quantity'),
        ];

        $topCustomers = (clone $ordersQ)->where('order_date', '>=', $fyStart)
            ->selectRaw('customer_id, SUM(total_amount) as total, COUNT(*) as orders')
            ->groupBy('customer_id')->orderByDesc('total')->limit(8)
            ->with('customer:id,name')->get()
            ->map(fn ($r) => ['name' => $r->customer->name ?? '—', 'total' => round((float) $r->total, 2), 'orders' => (int) $r->orders])
            ->all();

        $byState = (clone $ordersQ)->where('orders.order_date', '>=', $fyStart)
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->selectRaw('customers.state as state, SUM(orders.total_amount) as total')
            ->groupBy('customers.state')->orderByDesc('total')->get()
            ->map(fn ($r) => ['label' => $r->state ?: 'Unknown', 'value' => round((float) $r->total, 2)]);

        $quoteStatus = Quotation::where('company_id', $companyId)->where('created_at', '>=', $fyStart)
            ->selectRaw('status, COUNT(*) as n, SUM(total_amount) as total')->groupBy('status')->get()->keyBy('status');
        $won  = (int) ($quoteStatus['accepted']->n ?? 0);
        $lost = (int) ($quoteStatus['rejected']->n ?? 0);

        $invoicedFy  = (float) (clone $invoicedQ)->where('invoice_date', '>=', $fyStart)->sum('total_amount');
        $collectedFy = $this->collectedThisFinancialYear($companyId);
        $ordersFy    = (clone $ordersQ)->where('order_date', '>=', $fyStart);
        $orderCount  = (clone $ordersFy)->count();
        $invoiced90  = (float) (clone $invoicedQ)->where('invoice_date', '>=', now()->subDays(90)->startOfDay())->sum('total_amount');

        $deliveries = Dispatch::where('company_id', $companyId)->where('status', 'delivered')
            ->whereNotNull('actual_delivery_date')->get(['actual_delivery_date', 'expected_delivery_date']);
        $onTime = $deliveries->filter(fn ($d) => !$d->expected_delivery_date || $d->actual_delivery_date <= $d->expected_delivery_date)->count();

        return [
            'trend' => $trend,
            'headline' => [
                'invoiced_fy'        => round($invoicedFy, 2),
                'avg_order_value'    => $orderCount ? round((float) (clone $ordersFy)->sum('total_amount') / $orderCount, 2) : 0,
                'orders_fy'          => $orderCount,
                'win_rate_pct'       => ($won + $lost) ? round($won / ($won + $lost) * 100) : null,
                'collection_eff_pct' => $invoicedFy > 0 ? round($collectedFy / $invoicedFy * 100) : null,
                // DSO = outstanding ÷ average daily billing over the last 90 days
                'dso_days'           => $invoiced90 > 0 ? round($this->totalOutstanding($companyId) / ($invoiced90 / 90)) : null,
                'on_time_pct'        => $deliveries->count() ? round($onTime / $deliveries->count() * 100) : null,
            ],
            'top_customers'  => $topCustomers,
            'revenue_by_state' => $this->foldOther($byState, 6),
            'quotation_outcomes' => collect(['draft', 'sent', 'accepted', 'rejected'])->map(fn ($s) => [
                'label' => $s, 'count' => (int) ($quoteStatus[$s]->n ?? 0), 'value' => round((float) ($quoteStatus[$s]->total ?? 0), 2),
            ])->all(),
            'lead_funnel' => $this->countBy(\App\Models\Lead::where('company_id', $companyId), 'status', ['new', 'contacted', 'qualified', 'quoted', 'won', 'lost']),
            'lead_sources' => \App\Models\Lead::where('company_id', $companyId)->where('status', '!=', 'invalid')->where('created_at', '>=', $fyStart)
                ->selectRaw("source, COUNT(*) as n, SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) as won")
                ->groupBy('source')->orderByDesc('n')->get()
                ->map(fn ($r) => ['label' => $r->source, 'value' => (int) $r->n, 'won' => (int) $r->won])->all(),
            'payment_methods' => $this->foldOther(
                (clone $paidQ)->where('transaction_date', '>=', $fyStart)
                    ->selectRaw('payment_method, SUM(amount) as total')->groupBy('payment_method')->orderByDesc('total')->get()
                    ->map(fn ($r) => ['label' => str_replace('_', ' ', $r->payment_method), 'value' => round((float) $r->total, 2)]),
                4
            ),
            'batch_status' => $this->countBy(ProductionBatch::where('company_id', $companyId), 'status', ['draft', 'in_progress', 'completed', 'qc_pending', 'qc_passed', 'qc_failed', 'dispatched']),
            'dispatch_status' => $this->countBy(Dispatch::where('company_id', $companyId), 'status', ['pending', 'in_transit', 'delivered', 'cancelled']),
        ];
    }

    // ponytail: buckets in PHP so it runs on MySQL and the SQLite test DB alike; move to a GROUP BY on a month expression if row counts get large.
    private function monthlySum($query, string $dateCol, string $valCol, $from): \Illuminate\Support\Collection
    {
        return $query->where($dateCol, '>=', $from)->get([$dateCol, $valCol])
            ->groupBy(fn ($r) => \Carbon\Carbon::parse($r->$dateCol)->format('Y-m'))
            ->map(fn ($g) => $g->sum($valCol));
    }

    private function countBy($query, string $col, array $keys): array
    {
        $counts = $query->selectRaw("{$col}, COUNT(*) as n")->groupBy($col)->pluck('n', $col);
        return array_map(fn ($k) => ['label' => $k, 'value' => (int) ($counts[$k] ?? 0)], $keys);
    }

    /** Keep the top $keep rows and fold the rest into "Other" (charts never get a generated 9th hue). */
    private function foldOther(\Illuminate\Support\Collection $rows, int $keep): array
    {
        $out = $rows->take($keep)->values();
        if ($rows->count() > $keep) {
            $out->push(['label' => 'Other', 'value' => round($rows->slice($keep)->sum('value'), 2)]);
        }
        return $out->all();
    }

    private function fyStart(): \Carbon\Carbon
    {
        $today = now();
        return \Carbon\Carbon::create($today->month >= 4 ? $today->year : $today->year - 1, 4, 1)->startOfDay();
    }

    /**
     * What a new tenant still has to do before the system will work for them.
     *
     * Every item here silently blocks a real flow rather than erroring
     * helpfully: no panel type means nothing can be quoted; a zero base price
     * makes rate suggestions meaningless; a missing coil row makes the BOM read
     * zero available and the first production run dies with "Insufficient
     * stock"; no company state code sends every invoice to intra-state GST.
     */
    private function setupChecklist(int $companyId): array
    {
        $company = \App\Models\Company::find($companyId);

        $panelTypes  = \App\Models\PanelType::where('company_id', $companyId)->where('is_active', true)->count();
        $pricedTypes = \App\Models\PanelType::where('company_id', $companyId)->where('is_active', true)
            ->where('base_price', '>', 0)->count();
        $customers   = \App\Models\Customer::where('company_id', $companyId)->where('is_active', true)->count();
        $stages      = \App\Models\ProductionStage::where('company_id', $companyId)->where('is_active', true)->count();

        // Coil is resolved per panel type, so a type with no coil row can never be produced.
        $typesWithoutCoil = $panelTypes === 0 ? 0 : \App\Models\PanelType::where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNotExists(function ($q) use ($companyId) {
                $q->selectRaw(1)->from('coil_stocks')
                  ->whereColumn('coil_stocks.panel_type_id', 'panel_types.id')
                  ->where('coil_stocks.company_id', $companyId)
                  ->whereNull('coil_stocks.deleted_at');
            })->count();

        $chemicals = \App\Models\ChemicalStock::where('company_id', $companyId)
            ->whereIn('category', ['Polyol', 'Isocyanate'])->count();

        $items = [
            ['key' => 'company_gst', 'label' => 'Add your GSTIN and state', 'go' => 'company',
             'done' => !empty($company?->gstin) && !empty($company?->state_code),
             'hint' => 'Decides whether invoices carry CGST+SGST or IGST.'],
            ['key' => 'panel_types', 'label' => 'Create your panel types', 'go' => 'master',
             'done' => $panelTypes > 0,
             'hint' => 'Nothing can be quoted until at least one exists.'],
            ['key' => 'panel_prices', 'label' => 'Set a base price on each panel type', 'go' => 'master',
             'done' => $panelTypes > 0 && $pricedTypes === $panelTypes,
             'hint' => 'A zero base price makes suggested rates meaningless.'],
            ['key' => 'customers', 'label' => 'Add your first customer', 'go' => 'customers',
             'done' => $customers > 0,
             'hint' => 'Quotations are raised against a customer.'],
            ['key' => 'stages', 'label' => 'Set up production stages', 'go' => 'master',
             'done' => $stages > 0,
             'hint' => 'The shop floor tracks and signs off work stage by stage.'],
            ['key' => 'coil_stock', 'label' => 'Add coil stock for every panel type', 'go' => 'stock',
             'done' => $panelTypes > 0 && $typesWithoutCoil === 0,
             'hint' => $typesWithoutCoil > 0
                ? "{$typesWithoutCoil} panel type(s) have no coil row — production will fail with Insufficient stock."
                : 'Coil is tracked per panel type.'],
            ['key' => 'chemicals', 'label' => 'Add Polyol and Isocyanate stock', 'go' => 'stock',
             'done' => $chemicals >= 2,
             'hint' => 'The foam calculation looks these up by category — both must exist.'],
        ];

        $outstanding = array_values(array_filter($items, fn ($i) => !$i['done']));

        return [
            'done'        => count($outstanding) === 0,
            'complete'    => count($items) - count($outstanding),
            'total'       => count($items),
            'outstanding' => $outstanding,
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
            ->where('expected_delivery_date', '<', now()->startOfDay())->count();

        $batchesOnSchedule = ProductionBatch::where('company_id', $companyId)
            ->where('status', 'in_progress')->count();

        // Ready to ship = QC-passed batches with no dispatch yet
        $dispatchReady = ProductionBatch::where('company_id', $companyId)
            ->where('status', 'qc_passed')->count();

        $overdueInvoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled', 'paid'])
            ->where('due_date', '<', now()->startOfDay())->count();

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
            ->where('expiry_date', '>=', now()->startOfDay())
            ->where('expiry_date', '<', now()->addDays(31)->startOfDay())
            ->count();
        if ($expiring > 0) {
            $alerts[] = ['type' => 'expiring', 'severity' => 'warning', 'message' => "{$expiring} chemical(s) expiring within 30 days"];
        }

        // Expired chemicals
        $expired = ChemicalStock::where('company_id', $companyId)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<', now()->startOfDay())
            ->where('quantity_in_stock', '>', 0)
            ->count();
        if ($expired > 0) {
            $alerts[] = ['type' => 'expired', 'severity' => 'danger', 'message' => "{$expired} chemical(s) expired with stock on hand"];
        }

        // Overdue invoices
        $overdue = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled', 'paid'])
            ->where('due_date', '<', now()->startOfDay())
            ->count();
        if ($overdue > 0) {
            $alerts[] = ['type' => 'overdue', 'severity' => 'danger', 'message' => "{$overdue} invoice(s) overdue for payment"];
        }

        // Expired quotations still open
        $expiredQuotes = Quotation::where('company_id', $companyId)
            ->whereIn('status', ['draft', 'sent'])
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', now()->startOfDay())
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

        // One grouped query for paid-per-invoice instead of a SUM per invoice (SCALE-C2).
        $paid = PaymentTransaction::whereIn('invoice_id', $invoices->pluck('id'))
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(amount) as paid')
            ->pluck('paid', 'invoice_id');

        $total = 0;
        foreach ($invoices as $inv) {
            $total += max(0, $inv->total_amount - (float) ($paid[$inv->id] ?? 0));
        }
        return $total;
    }

    private function collectedThisFinancialYear(int $companyId): float
    {
        return (float) PaymentTransaction::where('company_id', $companyId)
            ->where('transaction_date', '>=', $this->fyStart())
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
