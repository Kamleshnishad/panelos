<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Quotation;
use Illuminate\Http\Request;

/**
 * Bulk CSV export of core records (opens directly in Excel). Tenant-scoped by
 * the global tenant scope; date range optional via ?from=&to=.
 */
class ExportController extends Controller
{
    private function csv(string $filename, array $headers, $rows): \Symfony\Component\HttpFoundation\Response
    {
        $out = [implode(',', $headers)];
        foreach ($rows as $r) {
            $out[] = implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) ($v ?? '')) . '"', $r));
        }
        return response(implode("\n", $out), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function range(Request $r, $q, string $col = 'created_at')
    {
        if ($from = $r->query('from')) $q->whereDate($col, '>=', $from);
        if ($to = $r->query('to'))     $q->whereDate($col, '<=', $to);
        return $q;
    }

    public function customers(Request $r)
    {
        $rows = Customer::orderBy('name')->get()->map(fn ($c) => [
            $c->name, $c->code, $c->type, $c->contact_person, $c->phone, $c->email,
            $c->gstin, $c->city, $c->state, $c->pincode, $c->credit_limit, $c->payment_terms_days,
        ]);
        return $this->csv('customers_' . now()->format('Ymd') . '.csv',
            ['Name', 'Code', 'Type', 'Contact', 'Phone', 'Email', 'GSTIN', 'City', 'State', 'Pincode', 'Credit Limit', 'Payment Terms (days)'], $rows);
    }

    public function quotations(Request $r)
    {
        $q = $this->range($r, Quotation::with('customer')->orderByDesc('created_at'));
        $rows = $q->get()->map(fn ($x) => [
            $x->quotation_no, optional($x->quoted_on)->format('Y-m-d'), $x->customer->name ?? '',
            $x->status, $x->total_sqm, $x->subtotal, $x->tax_amount, $x->total_amount,
            optional($x->valid_until)->format('Y-m-d'), $x->project_name,
        ]);
        return $this->csv('quotations_' . now()->format('Ymd') . '.csv',
            ['Quotation No', 'Date', 'Customer', 'Status', 'Total SQM', 'Subtotal', 'Tax', 'Total', 'Valid Until', 'Project'], $rows);
    }

    public function orders(Request $r)
    {
        $q = $this->range($r, Order::with('customer')->orderByDesc('created_at'));
        $rows = $q->get()->map(fn ($x) => [
            $x->order_no, optional($x->order_date)->format('Y-m-d'), $x->customer->name ?? '',
            $x->status, $x->total_sqm, $x->subtotal, $x->tax_amount, $x->total_amount,
            optional($x->expected_delivery_date)->format('Y-m-d'), $x->project_name,
        ]);
        return $this->csv('orders_' . now()->format('Ymd') . '.csv',
            ['Order No', 'Date', 'Customer', 'Status', 'Total SQM', 'Subtotal', 'Tax', 'Total', 'Expected Delivery', 'Project'], $rows);
    }

    public function invoices(Request $r)
    {
        $q = $this->range($r, Invoice::with('taxCalculation', 'payments', 'order.customer', 'dispatch.batch.order.customer')->orderByDesc('created_at'), 'invoice_date');
        $rows = $q->get()->map(function ($x) {
            $cust = $x->order->customer ?? $x->dispatch?->batch?->order?->customer;
            $paid = $x->payments->sum('amount');
            return [
                $x->invoice_no, optional($x->invoice_date)->format('Y-m-d'), $cust->name ?? '',
                $x->status, $x->subtotal, ($x->taxCalculation->tax_amount ?? $x->tax_amount), $x->total_amount,
                $paid, max(0, $x->total_amount - $paid), optional($x->due_date)->format('Y-m-d'),
                $x->irn ?: '', $x->eway_bill_no ?: '',
            ];
        });
        return $this->csv('invoices_' . now()->format('Ymd') . '.csv',
            ['Invoice No', 'Date', 'Customer', 'Status', 'Subtotal', 'Tax', 'Total', 'Paid', 'Balance', 'Due Date', 'IRN', 'e-Way Bill'], $rows);
    }
}
