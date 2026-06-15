<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\ProductionRun;
use App\Models\TaxCalculation;
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

        $query = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('invoice_date', [$from_date, $to_date]);

        $invoices = $query->with('items', 'taxCalculation')->get();

        $totalRevenue = $invoices->sum(function ($inv) {
            return $inv->subtotal;
        });

        $totalTax = $invoices->sum(function ($inv) {
            return $inv->taxCalculation->tax_amount ?? 0;
        });

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
            'invoice_count' => $invoices->count(),
            'average_invoice_value' => $invoices->count() > 0 ? round($grossRevenue / $invoices->count(), 2) : 0
        ];
    }

    public function getBalanceSheet($companyId = null, $asOf = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;
        $asOf = $asOf ?? now();

        $query = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->where('invoice_date', '<=', $asOf);

        $invoices = $query->with('items', 'taxCalculation')->get();

        $totalRevenue = $invoices->sum(function ($inv) {
            return $inv->subtotal;
        });

        $totalTax = $invoices->sum(function ($inv) {
            return $inv->taxCalculation->tax_amount ?? 0;
        });

        $totalPaid = PaymentTransaction::where('company_id', $companyId)
            ->where('created_at', '<=', $asOf)
            ->sum('amount');

        $accountsReceivable = ($totalRevenue + $totalTax) - $totalPaid;

        return [
            'as_of_date' => $asOf,
            'assets' => [
                'accounts_receivable' => max(0, $accountsReceivable),
                'cash_collected' => $totalPaid
            ],
            'liabilities' => [
                'tax_payable' => $totalTax
            ],
            'equity' => [
                'retained_earnings' => max(0, $totalRevenue - $totalTax)
            ],
            'total_assets' => max(0, $accountsReceivable) + $totalPaid,
            'total_liabilities_and_equity' => $totalTax + max(0, $totalRevenue - $totalTax)
        ];
    }

    public function getCashFlowStatement($companyId = null, $from_date = null, $to_date = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $from_date = $from_date ?? now()->subYear();
        $to_date = $to_date ?? now();

        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('invoice_date', [$from_date, $to_date])
            ->with('items', 'taxCalculation')
            ->get();

        $invoiceRevenue = $invoices->sum(function ($inv) {
            return $inv->subtotal + ($inv->taxCalculation->tax_amount ?? 0);
        });

        $payments = PaymentTransaction::where('company_id', $companyId)
            ->whereBetween('transaction_date', [$from_date, $to_date])
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
                'net_operating_cash_flow' => $cashInflow - $writeOffs
            ],
            'invoice_count' => $invoices->count(),
            'payment_transaction_count' => $payments->count()
        ];
    }

    public function getAccountsReceivable($companyId = null, $asOf = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;
        $asOf = $asOf ?? now();

        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->where('invoice_date', '<=', $asOf)
            ->with('dispatch.batch.order.customer', 'items', 'taxCalculation')
            ->get();

        $arData = $invoices->map(function ($invoice) {
            $total = $invoice->subtotal + ($invoice->taxCalculation->tax_amount ?? 0);
            $paid = PaymentTransaction::where('invoice_id', $invoice->id)->sum('amount');
            $remaining = max(0, $total - $paid);
            // Whole days past due as a positive integer; 0 if not yet due
            $daysOverdue = $invoice->due_date && $invoice->due_date->isPast()
                ? (int) floor($invoice->due_date->diffInDays(now()))
                : 0;

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

        $summary = [
            'total_ar' => $arData->sum('remaining_due'),
            'current' => $arData->filter(function ($row) { return $row['days_overdue'] === 0; })->sum('remaining_due'),
            '30_days' => $arData->filter(function ($row) { return $row['days_overdue'] > 0 && $row['days_overdue'] <= 30; })->sum('remaining_due'),
            '60_days' => $arData->filter(function ($row) { return $row['days_overdue'] > 30 && $row['days_overdue'] <= 60; })->sum('remaining_due'),
            '90_days' => $arData->filter(function ($row) { return $row['days_overdue'] > 60 && $row['days_overdue'] <= 90; })->sum('remaining_due'),
            'over_90_days' => $arData->filter(function ($row) { return $row['days_overdue'] > 90; })->sum('remaining_due'),
            'invoice_count' => $arData->count(),
            'overdue_count' => $arData->filter(function ($row) { return $row['is_overdue']; })->count()
        ];

        return [
            'summary' => $summary,
            'details' => $arData
        ];
    }

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
                'total' => $inv->subtotal + ($inv->taxCalculation->tax_amount ?? 0),
                'status' => $inv->status,
                'item_count' => $inv->items->count()
            ];
        });

        $panelSales = [];
        foreach ($invoices as $invoice) {
            foreach ($invoice->items as $item) {
                $panelType = $item->panelType?->type ?? 'Unknown';
                if (!isset($panelSales[$panelType])) {
                    $panelSales[$panelType] = [
                        'quantity' => 0,
                        'value' => 0,
                        'count' => 0
                    ];
                }
                $panelSales[$panelType]['quantity'] += $item->quantity;
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

    public function getAccountingDashboard($companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $thisYear = now()->startOfYear();
        $thisMonth = now()->startOfMonth();

        $plStatement = $this->getProfitLossStatement($companyId, $thisYear, now());
        $balanceSheet = $this->getBalanceSheet($companyId, now());
        $arAging = $this->getAccountsReceivable($companyId);
        $salesReport = $this->getSalesReport($companyId, $thisMonth, now());

        $invoices = Invoice::where('company_id', $companyId)
            ->where('status', '!=', 'draft')
            ->orderBy('invoice_date', 'desc')
            ->limit(5)
            ->get();

        return [
            'summary' => [
                'total_revenue_ytd' => $plStatement['revenue']['gross_revenue'],
                'total_accounts_receivable' => $arAging['summary']['total_ar'],
                'total_overdue' => $arAging['summary']['over_90_days'] + $arAging['summary']['90_days'] + $arAging['summary']['60_days'],
                'overdue_count' => $arAging['summary']['overdue_count'],
                'cash_collected_mtd' => $salesReport['summary']['total_value']
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

        foreach ($invoices as $invoice) {
            $total = $invoice->subtotal + ($invoice->taxCalculation->tax_amount ?? 0);
            $paid = PaymentTransaction::where('invoice_id', $invoice->id)->sum('amount');

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
        $invoiced = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->where('invoice_date', '>=', $start)
            ->get()
            ->groupBy(fn ($inv) => $inv->invoice_date->format('Y-m'))
            ->map(fn ($g) => $g->sum('total_amount'));

        // Collected per month (by payment date, excluding write-offs)
        $collected = PaymentTransaction::where('company_id', $companyId)
            ->where('transaction_date', '>=', $start)
            ->where('payment_method', '!=', 'write_off')
            ->get()
            ->groupBy(fn ($p) => \Carbon\Carbon::parse($p->transaction_date)->format('Y-m'))
            ->map(fn ($g) => $g->sum('amount'));

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

        $byCustomer = [];
        foreach ($invoices as $inv) {
            $customer = $inv->dispatch?->batch?->order?->customer ?? $inv->order?->customer;
            $cid   = $customer?->id ?? 0;
            $cname = $customer?->name ?? 'Unknown';
            if (!isset($byCustomer[$cid])) {
                $byCustomer[$cid] = ['customer_id' => $cid, 'customer_name' => $cname, 'invoiced' => 0, 'paid' => 0, 'invoice_count' => 0];
            }
            $paid = PaymentTransaction::where('invoice_id', $inv->id)->sum('amount');
            $byCustomer[$cid]['invoiced'] += (float) $inv->total_amount;
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
                $name = $item->panelType?->name ?? 'Unknown';
                if (!isset($mix[$name])) {
                    $mix[$name] = ['panel_type' => $name, 'sqm' => 0, 'value' => 0];
                }
                $mix[$name]['sqm']   += (float) $item->quantity;
                $mix[$name]['value'] += (float) $item->amount;
            }
        }

        $rows = collect($mix)->map(function ($r) {
            $r['sqm']   = round($r['sqm'], 2);
            $r['value'] = round($r['value'], 2);
            return $r;
        })->sortByDesc('value')->values();

        $totalValue = $rows->sum('value');
        $rows = $rows->map(function ($r) use ($totalValue) {
            $r['value_pct'] = $totalValue > 0 ? round(($r['value'] / $totalValue) * 100, 1) : 0;
            return $r;
        });

        return [
            'total_sqm'   => round($rows->sum('sqm'), 2),
            'total_value' => round($totalValue, 2),
            'rows'        => $rows->all(),
        ];
    }

    /**
     * MIS Report — owner-level monthly summary:
     * revenue, collection, production (sqm + runs), GST liability, outstanding.
     */
    /**
     * Order-to-invoice reconciliation: per order, ordered value vs invoiced vs
     * paid — flags revenue leakage (delivered but not / under-invoiced).
     */
    public function getReconciliation(int $companyId, ?string $from = null, ?string $to = null): array
    {
        $orders = Order::where('company_id', $companyId)
            ->where('status', '!=', 'cancelled')
            ->with('customer');
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
            $byOrder[$oid]['invoiced'] += (float) $inv->total_amount;
            $byOrder[$oid]['paid']     += (float) $inv->payments->sum('amount');
        }

        $rows = $orders->map(function ($o) use ($byOrder) {
            $ordered  = (float) $o->total_amount;
            $invoiced = $byOrder[$o->id]['invoiced'] ?? 0.0;
            $paid     = $byOrder[$o->id]['paid'] ?? 0.0;
            $gap      = round($ordered - $invoiced, 2);

            $flag = 'ok';
            if ($invoiced <= 0.01)            $flag = 'not_invoiced';
            elseif ($gap > 1)                 $flag = 'under_invoiced';
            elseif ($gap < -1)                $flag = 'over_invoiced';

            return [
                'order_no'      => $o->order_no,
                'order_id'      => $o->id,
                'date'          => optional($o->order_date)->format('Y-m-d'),
                'customer'      => $o->customer->name ?? '—',
                'status'        => $o->status,
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
                'orders'         => $rows->count(),
                'total_ordered'  => round($rows->sum('ordered'), 2),
                'total_invoiced' => round($rows->sum('invoiced'), 2),
                'total_paid'     => round($rows->sum('paid'), 2),
                'revenue_leak'   => round($leak, 2),          // ordered but not invoiced
                'not_invoiced'   => $rows->where('flag', 'not_invoiced')->count(),
                'under_invoiced' => $rows->where('flag', 'under_invoiced')->count(),
            ],
        ];
    }

    public function getMisReport(int $companyId, string $from, string $to): array
    {
        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('invoice_date', [$from, $to])
            ->with('taxCalculation', 'payments')
            ->get();

        $totalInvoiced  = round((float) $invoices->sum('total_amount'), 2);
        $totalPaid      = round((float) $invoices->flatMap(fn ($i) => $i->payments)->sum('amount'), 2);
        $outstanding    = round($totalInvoiced - $totalPaid, 2);

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

        // Orders
        $orders = Order::where('company_id', $companyId)
            ->whereBetween('created_at', [$from, $to])->get();

        // Production runs completed in period
        $runs = ProductionRun::where('company_id', $companyId)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$from, $to])->get();
        $sqmProduced = round((float) $runs->sum('planned_sqm'), 2);

        // Monthly invoice breakdown
        $monthly = $invoices->groupBy(fn ($i) => $i->invoice_date->format('Y-m'))
            ->map(fn ($g, $key) => [
                'month'     => \Carbon\Carbon::parse($key . '-01')->format('M Y'),
                'invoiced'  => round((float) $g->sum('total_amount'), 2),
                'collected' => round((float) $g->flatMap(fn ($i) => $i->payments)->sum('amount'), 2),
                'count'     => $g->count(),
            ])
            ->values();

        // Aging: 0-30, 31-60, 61-90, 90+ days overdue (unpaid invoices)
        $aging = ['0_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
        foreach ($invoices->where('status', '!=', 'paid') as $inv) {
            if (!$inv->due_date || !$inv->due_date->isPast()) continue;
            $days = now()->diffInDays($inv->due_date);
            $bal  = max(0, (float) $inv->total_amount - (float) $inv->payments->sum('amount'));
            if ($bal <= 0) continue;
            if ($days <= 30)      $aging['0_30']   += $bal;
            elseif ($days <= 60)  $aging['31_60']  += $bal;
            elseif ($days <= 90)  $aging['61_90']  += $bal;
            else                  $aging['over_90'] += $bal;
        }

        return [
            'period'     => ['from' => $from, 'to' => $to],
            'revenue'    => [
                'invoiced'        => $totalInvoiced,
                'collected'       => $totalPaid,
                'outstanding'     => $outstanding,
                'collection_pct'  => $totalInvoiced > 0 ? round($totalPaid / $totalInvoiced * 100, 1) : 0,
            ],
            'gst'        => [
                'cgst' => round($cgst, 2),
                'sgst' => round($sgst, 2),
                'igst' => round($igst, 2),
                'total'=> round($cgst + $sgst + $igst, 2),
            ],
            'orders'     => ['count' => $orders->count()],
            'production' => ['runs' => $runs->count(), 'sqm_produced' => $sqmProduced],
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

        $vouchers = $invoices->map(function (Invoice $inv) {
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
                'total_amount'   => round((float) $inv->total_amount, 2),
                'sales_ledger'   => 'Sales @ ' . $taxRate . '%',
                'hsn_summary'    => $inv->items->map(fn ($it) => [
                    'hsn'  => $it->panelType?->hsn_code ?? '39259010',
                    'desc' => ($it->panelType?->name ?? 'Panel') . ($it->panelType?->thickness ? ' ' . $it->panelType->thickness . 'mm' : ''),
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

        return compact('company', 'vouchers', 'totals', 'from', 'to');
    }
}
