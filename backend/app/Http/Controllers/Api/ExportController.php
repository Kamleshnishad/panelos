<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Quotation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk CSV export of core records (opens directly in Excel). Tenant-scoped by
 * the global tenant scope; date range optional via ?from=&to=.
 *
 * Streams via chunkById so a large tenant never buffers the whole table in
 * memory (SCALE-C3). Values are escaped against CSV/Excel formula injection.
 * Row order is by id (ascending) due to chunked streaming.
 */
class ExportController extends Controller
{
    /** Stream a query to CSV in id-chunks — constant memory regardless of row count. */
    private function streamCsv(string $filename, array $headers, $query, callable $rowMap): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $query, $rowMap) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_map([self::class, 'csvSafe'], $headers));
            $query->chunkById(1000, function ($rows) use ($out, $rowMap) {
                foreach ($rows as $model) {
                    fputcsv($out, array_map([self::class, 'csvSafe'], $rowMap($model)));
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralise CSV/Excel formula injection (=,+,-,@,tab,CR lead) by prefixing a quote. */
    private static function csvSafe($v): string
    {
        $s = (string) ($v ?? '');
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $s = "'" . $s;
        }
        return $s;
    }

    private function range(Request $r, $q, string $col = 'created_at')
    {
        if ($from = $r->query('from')) $q->whereDate($col, '>=', $from);
        if ($to = $r->query('to'))     $q->whereDate($col, '<=', $to);
        return $q;
    }

    public function customers(Request $r)
    {
        return $this->streamCsv(
            'customers_' . now()->format('Ymd') . '.csv',
            ['Name', 'Code', 'Type', 'Contact', 'Phone', 'Email', 'GSTIN', 'City', 'State', 'Pincode', 'Credit Limit', 'Payment Terms (days)'],
            Customer::query(),
            fn ($c) => [
                $c->name, $c->code, $c->type, $c->contact_person, $c->phone, $c->email,
                $c->gstin, $c->city, $c->state, $c->pincode, $c->credit_limit, $c->payment_terms_days,
            ]
        );
    }

    public function quotations(Request $r)
    {
        return $this->streamCsv(
            'quotations_' . now()->format('Ymd') . '.csv',
            ['Quotation No', 'Date', 'Customer', 'Status', 'Total SQM', 'Subtotal', 'Tax', 'Total', 'Valid Until', 'Project'],
            $this->range($r, Quotation::with('customer')),
            fn ($x) => [
                $x->quotation_no, optional($x->quoted_on)->format('Y-m-d'), $x->customer->name ?? '',
                $x->status, $x->total_sqm, $x->subtotal, $x->tax_amount, $x->total_amount,
                optional($x->valid_until)->format('Y-m-d'), $x->project_name,
            ]
        );
    }

    public function orders(Request $r)
    {
        return $this->streamCsv(
            'orders_' . now()->format('Ymd') . '.csv',
            ['Order No', 'Date', 'Customer', 'Status', 'Total SQM', 'Subtotal', 'Tax', 'Total', 'Expected Delivery', 'Project'],
            $this->range($r, Order::with('customer')),
            fn ($x) => [
                $x->order_no, optional($x->order_date)->format('Y-m-d'), $x->customer->name ?? '',
                $x->status, $x->total_sqm, $x->subtotal, $x->tax_amount, $x->total_amount,
                optional($x->expected_delivery_date)->format('Y-m-d'), $x->project_name,
            ]
        );
    }

    public function invoices(Request $r)
    {
        return $this->streamCsv(
            'invoices_' . now()->format('Ymd') . '.csv',
            ['Invoice No', 'Date', 'Customer', 'Status', 'Subtotal', 'Tax', 'Total', 'Paid', 'Balance', 'Due Date', 'IRN', 'e-Way Bill'],
            $this->range($r, Invoice::with('taxCalculation', 'payments', 'order.customer', 'dispatch.batch.order.customer'), 'invoice_date'),
            function ($x) {
                $cust = $x->order->customer ?? $x->dispatch?->batch?->order?->customer;
                $paid = $x->payments->sum('amount');
                return [
                    $x->invoice_no, optional($x->invoice_date)->format('Y-m-d'), $cust->name ?? '',
                    $x->status, $x->subtotal, ($x->taxCalculation->tax_amount ?? $x->tax_amount), $x->total_amount,
                    $paid, max(0, $x->total_amount - $paid), optional($x->due_date)->format('Y-m-d'),
                    $x->irn ?: '', $x->eway_bill_no ?: '',
                ];
            }
        );
    }
}
