<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\ProductionRun;
use App\Models\TaxCalculation;
use App\Support\Days;
use App\Support\InvoiceFigures;
use App\Support\TallyLedgers;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportingService
{
    protected $paymentService;
    protected $taxService;

    public function __construct(PaymentService $paymentService, TaxService $taxService)
    {
        $this->paymentService = $paymentService;
        $this->taxService = $taxService;
    }

    public function getProfitLossStatement($companyId = null, $from_date = null, $to_date = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $from_date = $from_date ?? now()->subYear();
        $to_date = $to_date ?? now();

        $agg = $this->invoiceAggregates($companyId, fn ($q) => $q->whereBetween('invoice_date', [$from_date, $to_date]));
        $totalRevenue = $agg['revenue'];
        $totalTax = $agg['tax'];
        $invoiceCount = $agg['count'];

        $grossRevenue = $totalRevenue + $totalTax;

        return [
            'period' => [
                'from_date' => $from_date,
                'to_date' => $to_date
            ],
            'revenue' => [
                'sales' => $totalRevenue,
                'tax_collected' => $totalTax,
                'gross_revenue' => $grossRevenue
            ],
            'invoice_count' => $invoiceCount,
            'average_invoice_value' => $invoiceCount > 0 ? round($grossRevenue / $invoiceCount, 2) : 0
        ];
    }

    /**
     * Invoice count, subtotal sum and tax sum computed in SQL (no model hydration).
     * $scope adds the caller's date constraints to the base company/status filter.
     */
    private function invoiceAggregates(int $companyId, \Closure $scope): array
    {
        $base = DB::table('invoices')
            ->where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled']);
        $scope($base);

        $row = (clone $base)->selectRaw('COUNT(*) AS n, COALESCE(SUM(subtotal), 0) AS revenue')->first();
        $tax = DB::table('tax_calculations as t')
            ->joinSub((clone $base)->select('id'), 'si', 'si.id', '=', 't.invoice_id')
            ->sum('t.tax_amount');

        return ['count' => (int) $row->n, 'revenue' => (float) $row->revenue, 'tax' => (float) $tax];
    }

    /**
     * A simplified balance sheet as of a date (PB-073, PB-080). The system holds no costs, stock values or bank balances, so it is built only from what has been
     * billed and received, and it balances by construction: assets (money still owed to us + cash received) = liabilities (GST billed, money received in advance)
     * + equity (what is left: net sales less write-offs). Payments count by the day they were received, up to the end of the as-of day.
     */
    public function getBalanceSheet($companyId = null, $asOf = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;
        $asOf = $asOf ?? now();
        $end = Carbon::parse($asOf)->endOfDay();

        $agg = $this->invoiceAggregates($companyId, fn ($q) => $q->where('invoice_date', '<=', $end->toDateString()));
        $billed = $agg['revenue'] + $agg['tax'];

        $received = PaymentTransaction::where('company_id', $companyId)
            ->where('transaction_date', '<=', $end)
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_method = 'write_off' THEN 0 ELSE amount END), 0) AS cash, COALESCE(SUM(CASE WHEN payment_method = 'write_off' THEN amount ELSE 0 END), 0) AS written_off")
            ->first();
        $cash = (float) $received->cash;
        $writeOffs = (float) $received->written_off;

        $receivable = $billed - $cash - $writeOffs;          // negative = customers have paid more than they were billed so far (an advance)
        $accountsReceivable = max(0, $receivable);
        $advances = max(0, -$receivable);

        $totalAssets = $accountsReceivable + $cash;
        $liabilities = $agg['tax'] + $advances;
        $equity = $totalAssets - $liabilities;               // = net sales less write-offs

        return [
            'as_of_date' => $asOf,
            'assets' => [
                'accounts_receivable' => round($accountsReceivable, 2),
                'cash_collected' => round($cash, 2),
            ],
            'liabilities' => [
                'tax_payable' => round($agg['tax'], 2),
                'customer_advances' => round($advances, 2),
            ],
            'equity' => [
                'retained_earnings' => round($equity, 2),
            ],
            'total_assets' => round($totalAssets, 2),
            'total_liabilities_and_equity' => round($liabilities + $equity, 2),
        ];
    }

    public function getCashFlowStatement($companyId = null, $from_date = null, $to_date = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $from_date = $from_date ?? now()->subYear();
        $to_date = $to_date ?? now();

        $agg = $this->invoiceAggregates($companyId, fn ($q) => $q->whereBetween('invoice_date', [$from_date, $to_date]));
        $invoiceRevenue = $agg['revenue'] + $agg['tax'];
        $invoiceCount = $agg['count'];

        // `transaction_date` is a date AND time, so a date-only `to` must mean the end of that day or every payment made on the last day is lost (PB-073).
        [$dayStart, $dayEnd] = $this->dayBounds($from_date, $to_date);
        $payments = PaymentTransaction::where('company_id', $companyId)
            ->whereBetween('transaction_date', [$dayStart, $dayEnd])
            ->get();

        $cashInflow = $payments->where('payment_method', '!=', 'write_off')->sum('amount');
        $writeOffs = $payments->where('payment_method', 'write_off')->sum('amount');

        return [
            'period' => [
                'from_date' => $from_date,
                'to_date' => $to_date
            ],
            'operating_activities' => [
                'invoices_issued' => $invoiceRevenue,
                'cash_collected' => $cashInflow,
                'write_offs' => $writeOffs,
                // Cash actually received. A write-off is a decision not to collect, not money that left the bank, so it is shown but never subtracted (PB-073).
                'net_operating_cash_flow' => $cashInflow
            ],
            'invoice_count' => $invoiceCount,
            'payment_transaction_count' => $payments->count()
        ];
    }

    /** The first instant of the first day and the last instant of the last day, for columns that hold a date and a time. */
    private function dayBounds($from, $to): array
    {
        return [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
    }

    /**
     * Bulk paid-amount-per-invoice map (one grouped query) — replaces per-invoice
     * SUM N+1 across AR / reconcile / top-customers (SCALE-C4). Numbers identical.
     * $until limits it to payments received up to that moment (receivables as of a past date, PB-078).
     */
    private function paidByInvoice($invoiceIds, $until = null): \Illuminate\Support\Collection
    {
        $ids = collect($invoiceIds)->filter()->values();
        if ($ids->isEmpty()) return collect();
        return PaymentTransaction::whereIn('invoice_id', $ids)
            ->when($until, fn ($q) => $q->where('transaction_date', '<=', $until))
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(amount) as paid')
            ->pluck('paid', 'invoice_id');
    }

    /** The ageing bucket of an invoice that is $days past its due date (0 = not yet due). */
    private function arBucket(int $days): string
    {
        return $days === 0 ? 'current' : ($days <= 30 ? '30_days' : ($days <= 60 ? '60_days' : ($days <= 90 ? '90_days' : 'over_90_days')));
    }

    /** What the Receivables screen's bucket buttons send, and the ageing bucket each one means. */
    public const AR_BUCKETS = ['current' => 'current', '30' => '30_days', '60' => '60_days', '90' => '90_days', '90plus' => 'over_90_days'];

    /**
     * Ageing summary plus the invoice rows behind it.
     *
     * The summary has to see every outstanding invoice to be correct, so the
     * scan stays. What is bounded is `details`: it used to return every row to
     * the browser, and receivables are exactly what grows when business is good.
     * Zero rows are dropped from the list (they carry no balance) but still
     * count towards the summary.
     *
     * "As of" a date means what was true on that date: only payments received up to the end of that day reduce a balance, and the days overdue are counted
     * to that day, not to today (PB-078). `$bucket` (one of AR_BUCKETS) narrows the LIST to one ageing bucket on the server, so paging and the bucket agree (PB-079);
     * the summary always covers everything, and `filtered_total` is the balance of the rows in the list.
     */
    public function getAccountsReceivable($companyId = null, $asOf = null, int $page = 1, int $perPage = 100, ?string $bucket = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;
        $asOf = $asOf ?? now();
        $asOfDay = Carbon::parse($asOf)->startOfDay();
        $asOfEnd = $asOfDay->copy()->endOfDay();

        // 'paid' is deliberately NOT excluded: legacy "Mark Paid" rows can carry a
        // real balance (audit E-C2), and hiding them would hide receivables.
        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->where('invoice_date', '<=', $asOfDay->toDateString())
            ->with([
                'dispatch.batch.order.customer:id,name',
                'taxCalculation:id,invoice_id,tax_amount',
            ])
            ->get(['id', 'company_id', 'dispatch_id', 'order_id', 'invoice_no', 'status', 'subtotal', 'tax_amount', 'total_amount', 'invoice_date', 'due_date']);

        $paidMap = $this->paidByInvoice($invoices->pluck('id'), $asOfEnd);

        $arData = $invoices->map(function ($invoice) use ($paidMap, $asOfDay) {
            $total = $invoice->getTotal();
            $paid = (float) ($paidMap[$invoice->id] ?? 0);
            $remaining = max(0, $total - $paid);
            // Whole days past due as of the report date; 0 if not yet due
            $daysOverdue = max(0, Days::overdue($invoice->due_date, $asOfDay));

            return [
                'invoice_id' => $invoice->id,
                'invoice_no' => $invoice->invoice_no,
                'customer_name' => $invoice->dispatch?->batch?->order?->customer?->name ?? 'N/A',
                'invoice_date' => $invoice->invoice_date,
                'due_date' => $invoice->due_date,
                'total_amount' => $total,
                'paid_amount' => $paid,
                'remaining_due' => $remaining,
                'status' => $invoice->status,
                'days_overdue' => $daysOverdue,
                'is_overdue' => $daysOverdue > 0
            ];
        });

        // Single pass over the rows instead of one filter()->sum() per bucket.
        $summary = ['total_ar' => 0, 'current' => 0, '30_days' => 0, '60_days' => 0, '90_days' => 0, 'over_90_days' => 0, 'invoice_count' => 0, 'overdue_count' => 0];
        foreach ($arData as $row) {
            $summary['total_ar'] += $row['remaining_due'];
            $summary['invoice_count']++;
            if ($row['is_overdue']) $summary['overdue_count']++;
            $summary[$this->arBucket($row['days_overdue'])] += $row['remaining_due'];
        }

        // Outstanding first, oldest debt at the top — what a collections person wants.
        $outstanding = $arData->filter(fn ($r) => $r['remaining_due'] > 0)
            ->sortByDesc('days_overdue')
            ->values();

        $want = $bucket !== null ? (self::AR_BUCKETS[$bucket] ?? null) : null;
        if ($want !== null) {
            $outstanding = $outstanding->filter(fn ($r) => $this->arBucket($r['days_overdue']) === $want)->values();
        }

        $perPage = max(1, min($perPage, 500));
        $page    = max(1, $page);
        $total   = $outstanding->count();

        return [
            'summary'        => $summary,
            'filtered_total' => round($outstanding->sum('remaining_due'), 2),
            'details'        => $outstanding->forPage($page, $perPage)->values(),
            'pagination'     => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    /** What accessory and other lines that are not a panel are called in the sales and panel-mix reports (PB-077). */
    public const OTHER_LINES = 'Accessories & other';

    public function getSalesReport($companyId = null, $from_date = null, $to_date = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $from_date = $from_date ?? now()->subYear();
        $to_date = $to_date ?? now();

        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('invoice_date', [$from_date, $to_date])
            ->with('items.panelType', 'taxCalculation')
            ->get();

        $invoiceSalesData = $invoices->map(function ($inv) {
            return [
                'invoice_no' => $inv->invoice_no,
                'invoice_date' => $inv->invoice_date,
                'amount' => $inv->subtotal,
                'tax' => $inv->taxCalculation->tax_amount ?? 0,
                'total' => $inv->getTotal(),
                'status' => $inv->status,
                'item_count' => $inv->items->count()
            ];
        });

        // A line with no panel type is an accessory or other charge: its quantity is pieces, kg or sets, so it is shown by value and never added to a panel's quantity.
        $panelSales = [];
        foreach ($invoices as $invoice) {
            foreach ($invoice->items as $item) {
                $isPanel = $item->panel_type_id !== null;
                $panelType = $isPanel ? ($item->panelType?->name ?? 'Unknown') : self::OTHER_LINES;      // panel_types has a `name`, never had a `type`: every panel used to be 'Unknown'
                if (!isset($panelSales[$panelType])) {
                    $panelSales[$panelType] = [
                        'quantity' => $isPanel ? 0 : null,
                        'value' => 0,
                        'count' => 0
                    ];
                }
                if ($isPanel) {
                    $panelSales[$panelType]['quantity'] += $item->quantity;
                }
                $panelSales[$panelType]['value'] += $item->amount;
                $panelSales[$panelType]['count']++;
            }
        }

        return [
            'period' => [
                'from_date' => $from_date,
                'to_date' => $to_date
            ],
            'summary' => [
                'total_sales' => $invoiceSalesData->sum('amount'),
                'total_tax' => $invoiceSalesData->sum('tax'),
                'total_value' => $invoiceSalesData->sum('total'),
                'invoice_count' => $invoices->count(),
                'average_invoice_value' => $invoices->count() > 0 ? round($invoiceSalesData->sum('total') / $invoices->count(), 2) : 0
            ],
            'by_invoice' => $invoiceSalesData,
            'by_panel_type' => $panelSales
        ];
    }

    public function getTaxReport($companyId = null, $from_date = null, $to_date = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        return $this->taxService->getTaxReport($companyId, $from_date, $to_date);
    }

    /**
     * First day of the financial year that contains $on (today by default). It starts on the first of the company's `financial_year_start` month
     * (Company settings, 4 = April, the Indian default), so a company on a calendar year, or one starting in July, gets its own year.
     */
    public static function financialYearStart(?Carbon $on = null, int $startMonth = 4): Carbon
    {
        $on = ($on ?? now())->copy();
        $startMonth = max(1, min(12, $startMonth));

        return Carbon::create($on->month >= $startMonth ? $on->year : $on->year - 1, $startMonth, 1)->startOfDay();
    }

    /**
     * One-page finance summary. 'ytd' is the financial year to date (from 1 April), `cash_collected_mtd` is money actually received this month and `invoiced_mtd` what
     * was billed this month (PB-080: the two used to be the same number under the cash label); `total_overdue` is everything past its due date.
     */
    public function getAccountingDashboard($companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $fyStart = self::financialYearStart(null, (int) (\App\Models\Company::withoutGlobalScope('tenant')->whereKey($companyId)->value('financial_year_start') ?: 4));
        $thisMonth = now()->startOfMonth();

        $plStatement = $this->getProfitLossStatement($companyId, $fyStart, now());
        $balanceSheet = $this->getBalanceSheet($companyId, now());
        $arAging = $this->getAccountsReceivable($companyId);
        $salesReport = $this->getSalesReport($companyId, $thisMonth, now());
        $cashMtd = $this->getCashFlowStatement($companyId, $thisMonth, now())['operating_activities']['cash_collected'];

        $invoices = Invoice::where('company_id', $companyId)
            ->where('status', '!=', 'draft')
            ->orderBy('invoice_date', 'desc')
            ->limit(5)
            ->get();

        return [
            'summary' => [
                'total_revenue_ytd' => $plStatement['revenue']['gross_revenue'],
                'total_accounts_receivable' => $arAging['summary']['total_ar'],
                'total_overdue' => $arAging['summary']['over_90_days'] + $arAging['summary']['90_days'] + $arAging['summary']['60_days'] + $arAging['summary']['30_days'],
                'overdue_count' => $arAging['summary']['overdue_count'],
                'cash_collected_mtd' => $cashMtd,
                'invoiced_mtd' => $salesReport['summary']['total_value'],
            ],
            'pl_statement' => $plStatement,
            'balance_sheet' => $balanceSheet,
            'ar_aging' => $arAging['summary'],
            'recent_invoices' => $invoices->map(function ($inv) {
                return [
                    'invoice_no' => $inv->invoice_no,
                    'amount' => $inv->subtotal,
                    'status' => $inv->status,
                    'due_date' => $inv->due_date
                ];
            })
        ];
    }

    public function reconcileInvoices($companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->with('taxCalculation')
            ->get();

        $reconciliation = [
            'total_invoiced' => 0,
            'total_paid' => 0,
            'total_outstanding' => 0,
            'invoices_reconciled' => 0,
            'discrepancies' => []
        ];

        $paidMap = $this->paidByInvoice($invoices->pluck('id'));

        foreach ($invoices as $invoice) {
            $total = $invoice->getTotal();
            $paid = (float) ($paidMap[$invoice->id] ?? 0);

            $reconciliation['total_invoiced'] += $total;
            $reconciliation['total_paid'] += $paid;
            $reconciliation['total_outstanding'] += max(0, $total - $paid);
            $reconciliation['invoices_reconciled']++;

            if ($invoice->status === 'paid' && $paid <= 0) {
                $reconciliation['discrepancies'][] = [
                    'invoice_id' => $invoice->id,
                    'issue' => 'Marked as paid but no payment records found'
                ];
            }
        }

        return $reconciliation;
    }

    /**
     * Monthly revenue trend (invoiced vs collected) over the last N months.
     */
    public function getMonthlyRevenueTrend($companyId = null, int $months = 12)
    {
        $companyId = $companyId ?? auth()->user()->company_id;
        $start = now()->copy()->subMonths($months - 1)->startOfMonth();

        // Invoiced per month (by invoice_date, non-draft/cancelled)
        // Grouped in SQL (was: load every row then groupBy in PHP) — SCALE-H3. Same sums.
        $invoiced = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->where('invoice_date', '>=', $start)
            ->selectRaw("DATE_FORMAT(invoice_date, '%Y-%m') as k, SUM(" . InvoiceFigures::TOTAL_SQL . ") as t")
            ->groupBy('k')->pluck('t', 'k');

        // Collected per month (by payment date, excluding write-offs)
        $collected = PaymentTransaction::where('company_id', $companyId)
            ->where('transaction_date', '>=', $start)
            ->where('payment_method', '!=', 'write_off')
            ->selectRaw("DATE_FORMAT(transaction_date, '%Y-%m') as k, SUM(amount) as a")
            ->groupBy('k')->pluck('a', 'k');

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $start->copy()->addMonths($i);
            $key = $m->format('Y-m');
            $series[] = [
                'month'     => $m->format('M Y'),
                'key'       => $key,
                'invoiced'  => round((float) ($invoiced[$key] ?? 0), 2),
                'collected' => round((float) ($collected[$key] ?? 0), 2),
            ];
        }

        return [
            'months' => $months,
            'series' => $series,
            'totals' => [
                'invoiced'  => round(collect($series)->sum('invoiced'), 2),
                'collected' => round(collect($series)->sum('collected'), 2),
            ],
        ];
    }

    /**
     * Top customers by total invoiced value in a period.
     */
    public function getTopCustomers($companyId = null, $from_date = null, $to_date = null, int $limit = 10)
    {
        $companyId = $companyId ?? auth()->user()->company_id;
        $from_date = $from_date ?? now()->copy()->subYear();
        $to_date   = $to_date ?? now();

        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('invoice_date', [$from_date, $to_date])
            ->with('dispatch.batch.order.customer', 'order.customer')
            ->get();

        $paidMap = $this->paidByInvoice($invoices->pluck('id'));

        $byCustomer = [];
        foreach ($invoices as $inv) {
            $customer = $inv->dispatch?->batch?->order?->customer ?? $inv->order?->customer;
            $cid   = $customer?->id ?? 0;
            $cname = $customer?->name ?? 'Unknown';
            if (!isset($byCustomer[$cid])) {
                $byCustomer[$cid] = ['customer_id' => $cid, 'customer_name' => $cname, 'invoiced' => 0, 'paid' => 0, 'invoice_count' => 0];
            }
            $paid = (float) ($paidMap[$inv->id] ?? 0);
            $byCustomer[$cid]['invoiced'] += $inv->getTotal();
            $byCustomer[$cid]['paid']     += (float) $paid;
            $byCustomer[$cid]['invoice_count']++;
        }

        return collect($byCustomer)
            ->map(function ($row) {
                $row['invoiced'] = round($row['invoiced'], 2);
                $row['paid']     = round($row['paid'], 2);
                $row['outstanding'] = round($row['invoiced'] - $row['paid'], 2);
                return $row;
            })
            ->sortByDesc('invoiced')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Panel type mix — SQM and value sold per panel type in a period.
     * Accessory and other lines (no panel type) are one separate row, "Accessories & other": they carry a value but no SQM, and are never added to the SQM
     * total (PB-077). A line whose panel type has since been removed is 'Unknown' and still counts as panel area.
     */
    public function getPanelTypeMix($companyId = null, $from_date = null, $to_date = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;
        $from_date = $from_date ?? now()->copy()->subYear();
        $to_date   = $to_date ?? now();

        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('invoice_date', [$from_date, $to_date])
            ->with('items.panelType')
            ->get();

        $mix = [];
        foreach ($invoices as $inv) {
            foreach ($inv->items as $item) {
                $isPanel = $item->panel_type_id !== null;
                $name = $isPanel ? ($item->panelType?->name ?? 'Unknown') : self::OTHER_LINES;
                if (!isset($mix[$name])) {
                    $mix[$name] = ['panel_type' => $name, 'sqm' => $isPanel ? 0 : null, 'value' => 0, 'is_panel' => $isPanel];
                }
                if ($isPanel) {
                    $mix[$name]['sqm'] += (float) $item->quantity;
                }
                $mix[$name]['value'] += (float) $item->amount;
            }
        }

        $rows = collect($mix)->map(function ($r) {
            $r['sqm']   = $r['sqm'] === null ? null : round($r['sqm'], 2);
            $r['value'] = round($r['value'], 2);
            return $r;
        })->sortByDesc('value')->values();

        $totalValue = $rows->sum('value');
        $rows = $rows->map(function ($r) use ($totalValue) {
            $r['value_pct'] = $totalValue > 0 ? round(($r['value'] / $totalValue) * 100, 1) : 0;
            return $r;
        });

        return [
            'total_sqm'   => round($rows->sum(fn ($r) => $r['sqm'] ?? 0), 2),
            'total_value' => round($totalValue, 2),
            'rows'        => $rows->all(),
        ];
    }

    /**
     * Order-to-invoice reconciliation: per order, ordered value vs invoiced vs
     * paid — flags revenue leakage (delivered but not / under-invoiced).
     *
     * Only an order that is FULLY delivered (marked completed, or every production batch dispatched) can be "not invoiced" or "under-invoiced", and only those
     * count towards `revenue_leak` (PB-076). An order not yet delivered is `not_delivered` (work in progress, not leakage); one delivered in part is
     * `part_delivered`, or `awaiting_invoice` if nothing has been invoiced yet: both are listed but their value is not counted as leakage because the value
     * of what has gone out is not known here.
     */
    public function getReconciliation(int $companyId, ?string $from = null, ?string $to = null): array
    {
        $orders = Order::where('company_id', $companyId)
            ->where('status', '!=', 'cancelled')
            ->with('customer', 'batches:id,order_id,status');
        if ($from) $orders->whereDate('order_date', '>=', $from);
        if ($to)   $orders->whereDate('order_date', '<=', $to);
        $orders = $orders->orderByDesc('order_date')->get();

        // Invoices linked directly (order_id) or via dispatch->batch->order
        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['cancelled'])
            ->with('payments', 'dispatch.batch')
            ->get();

        $byOrder = [];   // orderId => ['invoiced'=>, 'paid'=>]
        foreach ($invoices as $inv) {
            $oid = $inv->order_id ?: $inv->dispatch?->batch?->order_id;
            if (!$oid) continue;
            if (!isset($byOrder[$oid])) $byOrder[$oid] = ['invoiced' => 0.0, 'paid' => 0.0];
            $byOrder[$oid]['invoiced'] += $inv->getTotal();
            $byOrder[$oid]['paid']     += (float) $inv->payments->sum('amount');
        }

        $rows = $orders->map(function ($o) use ($byOrder) {
            $ordered  = (float) $o->total_amount;
            $invoiced = $byOrder[$o->id]['invoiced'] ?? 0.0;
            $paid     = $byOrder[$o->id]['paid'] ?? 0.0;
            $gap      = round($ordered - $invoiced, 2);

            $batches    = $o->batches->count();
            $dispatched = $o->batches->where('status', 'dispatched')->count();
            $delivery   = ($o->status === 'completed' || ($batches > 0 && $dispatched === $batches)) ? 'full' : ($dispatched > 0 ? 'part' : 'none');

            if ($delivery === 'none') {
                $flag = 'not_delivered';
            } elseif ($delivery === 'part') {
                $flag = $invoiced <= 0.01 ? 'awaiting_invoice' : 'part_delivered';
            } elseif ($invoiced <= 0.01) {
                $flag = 'not_invoiced';
            } elseif ($gap > 1) {
                $flag = 'under_invoiced';
            } elseif ($gap < -1) {
                $flag = 'over_invoiced';
            } else {
                $flag = 'ok';
            }

            return [
                'order_no'      => $o->order_no,
                'order_id'      => $o->id,
                'date'          => optional($o->order_date)->format('Y-m-d'),
                'customer'      => $o->customer->name ?? '—',
                'status'        => $o->status,
                'delivery'      => $delivery,
                'ordered'       => round($ordered, 2),
                'invoiced'      => round($invoiced, 2),
                'paid'          => round($paid, 2),
                'invoice_gap'   => $gap,
                'balance_due'   => round($invoiced - $paid, 2),
                'flag'          => $flag,
            ];
        });

        $leak = $rows->whereIn('flag', ['not_invoiced', 'under_invoiced'])->sum('invoice_gap');

        return [
            'rows'    => $rows->values()->all(),
            'summary' => [
                'orders'           => $rows->count(),
                'total_ordered'    => round($rows->sum('ordered'), 2),
                'total_invoiced'   => round($rows->sum('invoiced'), 2),
                'total_paid'       => round($rows->sum('paid'), 2),
                'revenue_leak'     => round($leak, 2),          // fully delivered but not (fully) invoiced
                'not_invoiced'     => $rows->where('flag', 'not_invoiced')->count(),
                'under_invoiced'   => $rows->where('flag', 'under_invoiced')->count(),
                'awaiting_invoice' => $rows->where('flag', 'awaiting_invoice')->count(),
                'not_delivered'    => $rows->where('flag', 'not_delivered')->count(),
            ],
        ];
    }

    /**
     * MIS for the invoices RAISED in a period, in the owner's words (PB-074, PB-080):
     *  - invoiced: what those invoices come to; collected: what has been received against them so far (write-offs are not collected); written_off; outstanding: what is still
     *    unpaid on them, which is exactly the sum of the ageing buckets below. This is not the same as the cash received in the period (the Dashboard tab's Cash Collected).
     *  - ageing: the unpaid balance of those invoices by how overdue they are TODAY, including 'not_due'; invoices marked paid that still carry a balance are included.
     *  - orders (by order date) and completed production runs (by completion time) cover the whole of the last day of the range, not just its first instant.
     *  - production: the runs' PLANNED sqm; no actual output is recorded, so `basis` says so.
     */
    public function getMisReport(int $companyId, string $from, string $to): array
    {
        [$dayStart, $dayEnd] = $this->dayBounds($from, $to);

        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('invoice_date', [$dayStart->toDateString(), $dayEnd->toDateString()])
            ->with('taxCalculation', 'payments')
            ->get();

        $cashOf = fn ($inv) => (float) $inv->payments->where('payment_method', '!=', 'write_off')->sum('amount');
        $writeOffOf = fn ($inv) => (float) $inv->payments->where('payment_method', 'write_off')->sum('amount');
        $unpaidOf = fn ($inv) => max(0, $inv->getTotal() - (float) $inv->payments->sum('amount'));

        $totalInvoiced  = round((float) $invoices->sum(fn ($i) => $i->getTotal()), 2);
        $totalPaid      = round((float) $invoices->sum($cashOf), 2);
        $totalWrittenOff = round((float) $invoices->sum($writeOffOf), 2);
        $outstanding    = round((float) $invoices->sum($unpaidOf), 2);

        // GST summary
        $cgst = $sgst = $igst = 0.0;
        foreach ($invoices as $inv) {
            $tc = $inv->taxCalculation;
            if ($tc) {
                $cgst += (float) $tc->cgst_amount;
                $sgst += (float) $tc->sgst_amount;
                $igst += (float) $tc->igst_amount;
            }
        }

        $ordersCount = Order::where('company_id', $companyId)
            ->whereBetween('order_date', [$dayStart->toDateString(), $dayEnd->toDateString()])->count();

        $runsQuery = ProductionRun::where('company_id', $companyId)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$dayStart, $dayEnd]);
        $runsCount   = (clone $runsQuery)->count();
        $sqmProduced = round((float) (clone $runsQuery)->sum('planned_sqm'), 2);

        // Monthly invoice breakdown: what each month's invoices came to, and how much has been paid against them so far
        $monthly = $invoices->groupBy(fn ($i) => $i->invoice_date->format('Y-m'))
            ->map(fn ($g, $key) => [
                'month'     => Carbon::parse($key . '-01')->format('M Y'),
                'invoiced'  => round((float) $g->sum(fn ($i) => $i->getTotal()), 2),
                'collected' => round((float) $g->sum($cashOf), 2),
                'count'     => $g->count(),
            ])
            ->values();

        // Ageing of what is still unpaid on these invoices, as of today
        $aging = ['not_due' => 0, '0_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
        foreach ($invoices as $inv) {
            $bal = $unpaidOf($inv);
            if ($bal <= 0) continue;
            $days = $inv->due_date ? Days::overdue($inv->due_date) : 0;
            if ($days <= 0)       $aging['not_due'] += $bal;
            elseif ($days <= 30)  $aging['0_30']    += $bal;
            elseif ($days <= 60)  $aging['31_60']   += $bal;
            elseif ($days <= 90)  $aging['61_90']   += $bal;
            else                  $aging['over_90'] += $bal;
        }

        return [
            'period'     => ['from' => $from, 'to' => $to],
            'revenue'    => [
                'invoiced'        => $totalInvoiced,
                'collected'       => $totalPaid,
                'written_off'     => $totalWrittenOff,
                'outstanding'     => $outstanding,
                'collection_pct'  => $totalInvoiced > 0 ? round($totalPaid / $totalInvoiced * 100, 1) : 0,
            ],
            'gst'        => [
                'cgst' => round($cgst, 2),
                'sgst' => round($sgst, 2),
                'igst' => round($igst, 2),
                'total'=> round($cgst + $sgst + $igst, 2),
            ],
            'orders'     => ['count' => $ordersCount],
            'production' => ['runs' => $runsCount, 'sqm_produced' => $sqmProduced, 'basis' => 'planned'],
            'aging'      => array_map(fn ($v) => round($v, 2), $aging),
            'monthly'    => $monthly,
        ];
    }

    /**
     * Tally-compatible export data for invoices in a period.
     * Returns structured rows that the controller serialises to XML or CSV.
     */
    public function getTallyExportData(int $companyId, string $from, string $to): array
    {
        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('invoice_date', [$from, $to])
            ->with('taxCalculation', 'items.panelType', 'dispatch.batch.order.customer', 'order.customer', 'company')
            ->get();

        $company = $invoices->first()?->company;

        $ledgers = TallyLedgers::for($companyId);          // the company's own ledger names and fallback HSN (FR-010)

        $vouchers = $invoices->map(function (Invoice $inv) use ($ledgers) {
            $tc       = $inv->taxCalculation;
            $customer = $inv->dispatch?->batch?->order?->customer ?? $inv->order?->customer;
            $cgst     = (float) ($tc->cgst_amount ?? 0);
            $sgst     = (float) ($tc->sgst_amount ?? 0);
            $igst     = (float) ($tc->igst_amount ?? 0);
            $taxable  = (float) ($tc->taxable_amount ?? $inv->subtotal);
            $taxRate  = (float) ($tc->tax_rate ?? 18);
            $isInter  = $igst > 0;

            return [
                'voucher_type'   => 'Sales',
                'voucher_no'     => $inv->invoice_no,
                'date'           => $inv->invoice_date->format('d-M-Y'),
                'date_tally'     => $inv->invoice_date->format('Ymd'),
                'party_name'     => $customer?->name ?? 'Unknown',
                'party_gstin'    => $customer?->gstin ?? '',
                'party_state'    => $customer?->state ?? '',
                'narration'      => 'Sales Invoice ' . $inv->invoice_no,
                'taxable_value'  => round($taxable, 2),
                'cgst_rate'      => $isInter ? 0 : $taxRate / 2,
                'cgst_amount'    => round($cgst, 2),
                'sgst_rate'      => $isInter ? 0 : $taxRate / 2,
                'sgst_amount'    => round($sgst, 2),
                'igst_rate'      => $isInter ? $taxRate : 0,
                'igst_amount'    => round($igst, 2),
                'total_amount'   => round($inv->getTotal(), 2),
                'sales_ledger'   => TallyLedgers::salesLedger($ledgers, $taxRate),
                'hsn_summary'    => $inv->items->map(fn ($it) => [
                    'hsn'  => $it->panelType?->hsn_code ?: $ledgers['hsn'],
                    'desc' => $it->panel_type_id === null ? ($it->description ?: 'Accessories & other') : (($it->panelType?->name ?? 'Panel') . ($it->panelType?->thickness ? ' ' . $it->panelType->thickness . 'mm' : '')),
                    'qty'  => (float) $it->quantity,
                    'rate' => (float) $it->unit_price,
                    'amt'  => (float) $it->amount,
                ])->all(),
            ];
        })->all();

        $totals = [
            'taxable'  => round(collect($vouchers)->sum('taxable_value'), 2),
            'cgst'     => round(collect($vouchers)->sum('cgst_amount'), 2),
            'sgst'     => round(collect($vouchers)->sum('sgst_amount'), 2),
            'igst'     => round(collect($vouchers)->sum('igst_amount'), 2),
            'total'    => round(collect($vouchers)->sum('total_amount'), 2),
            'count'    => count($vouchers),
        ];

        return compact('company', 'vouchers', 'totals', 'from', 'to', 'ledgers');
    }
}
