<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentTransaction;
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
}
