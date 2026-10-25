<?php

namespace App\Services;

use App\Http\Controllers\Api\ExportController;
use App\Models\PaymentTransaction;
use Carbon\Carbon;

/**
 * The payments-received register for a period (FR-010): every payment that came in on a day in the range (the first and last day included, by the
 * day it was received), with the invoice and customer it was against, newest first. Write-offs are listed too, marked, but they are not cash:
 * `received` is the money that came in, `written_off` what was written off, and `by_method` splits the money received.
 */
class PaymentsRegisterService
{
    public function build(int $companyId, string $from, string $to): array
    {
        $payments = PaymentTransaction::where('company_id', $companyId)
            ->whereBetween('transaction_date', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->with('invoice.order.customer', 'invoice.dispatch.batch.order.customer', 'createdByUser:id,name')
            ->orderByDesc('transaction_date')->orderByDesc('id')
            ->get();

        $rows = $payments->map(function (PaymentTransaction $p) {
            $inv = $p->invoice;
            $customer = $inv?->order?->customer ?? $inv?->dispatch?->batch?->order?->customer;

            return [
                'payment_id'    => $p->id,
                'date'          => Carbon::parse($p->transaction_date)->format('Y-m-d'),
                'invoice_id'    => $p->invoice_id,
                'invoice_no'    => $inv?->invoice_no,
                'customer_name' => $customer?->name ?? 'Unknown',
                'method'        => $p->payment_method,
                'reference'     => $p->reference_no ?: ($p->transaction_id ?: ''),
                'amount'        => round((float) $p->amount, 2),
                'is_write_off'  => $p->payment_method === 'write_off',
                'entered_by'    => $p->createdByUser?->name,
            ];
        });

        $cash = $rows->where('is_write_off', false);
        $byMethod = $cash->groupBy('method')->map(fn ($g, $m) => ['method' => $m, 'payments' => $g->count(), 'amount' => round($g->sum('amount'), 2)])->sortByDesc('amount')->values()->all();

        return [
            'period'  => ['from' => $from, 'to' => $to],
            'totals'  => [
                'payments'    => $cash->count(),
                'received'    => round($cash->sum('amount'), 2),
                'written_off' => round($rows->where('is_write_off', true)->sum('amount'), 2),
            ],
            'by_method' => $byMethod,
            'payments'  => $rows->values()->all(),
        ];
    }

    public function csv(array $data): string
    {
        $text = fn ($v) => ExportController::csvSafe($v);
        $lines = [['Date', 'Invoice No', 'Customer', 'Method', 'Reference', 'Amount', 'Write-off', 'Entered By']];
        foreach ($data['payments'] as $r) {
            $lines[] = [Carbon::parse($r['date'])->format('d-m-Y'), $text($r['invoice_no']), $text($r['customer_name']), $r['method'], $text($r['reference']), sprintf('%.2f', $r['amount']), $r['is_write_off'] ? 'Yes' : '', $text($r['entered_by'])];
        }
        $lines[] = ['TOTAL RECEIVED', '', '', '', '', sprintf('%.2f', $data['totals']['received']), '', ''];
        $lines[] = ['TOTAL WRITTEN OFF', '', '', '', '', sprintf('%.2f', $data['totals']['written_off']), '', ''];

        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        foreach ($lines as $l) {
            fputcsv($h, $l, ',', '"', '');
        }
        rewind($h);
        $out = stream_get_contents($h);
        fclose($h);

        return $out;
    }
}
