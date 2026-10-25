<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Support\Days;
use Carbon\Carbon;

/**
 * A customer's account statement for a period (FR-010): what we billed them and what they paid, in date order, with a running balance.
 *
 *  - Entries: each invoice that still stands (not draft, not cancelled) is a debit for its total on its invoice date; each payment is a credit on the day it
 *    was received. A write-off is a credit too, marked as one. On the same day an invoice comes before a payment.
 *  - Opening balance = everything dated before the period starts; closing balance = opening + the period's debits - credits. The statement therefore always adds up.
 *  - `outstanding` lists the invoices still unpaid at the end of the period, with their age to that day.
 * A customer's invoices are those raised against their orders, directly or through a dispatch.
 */
class AccountStatementService
{
    public function build(int $companyId, int $customerId, string $from, string $to): array
    {
        $customer = Customer::where('company_id', $companyId)->findOrFail($customerId);
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        $orderIds = Order::where('company_id', $companyId)->where('customer_id', $customerId)->pluck('id');
        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->where('invoice_date', '<=', $end->toDateString())
            ->where(function ($q) use ($orderIds) {
                $q->whereIn('order_id', $orderIds)->orWhereHas('dispatch.batch', fn ($b) => $b->whereIn('order_id', $orderIds));
            })
            ->with('taxCalculation', 'payments')
            ->orderBy('invoice_date')->orderBy('id')
            ->get();

        $entries = [];
        foreach ($invoices as $inv) {
            $entries[] = ['at' => $inv->invoice_date->copy()->startOfDay(), 'order' => 0, 'id' => $inv->id, 'type' => 'invoice', 'ref' => $inv->invoice_no,
                'description' => 'Invoice ' . $inv->invoice_no, 'debit' => round($inv->getTotal(), 2), 'credit' => 0.0];
            foreach ($inv->payments as $p) {
                $paidAt = Carbon::parse($p->transaction_date);
                if ($paidAt->gt($end)) {
                    continue;
                }
                $writeOff = $p->payment_method === 'write_off';
                $entries[] = ['at' => $paidAt, 'order' => 1, 'id' => $p->id, 'type' => $writeOff ? 'write_off' : 'payment', 'ref' => $p->reference_no ?: ($p->transaction_id ?: ''),
                    'description' => ($writeOff ? 'Written off' : 'Payment received (' . str_replace('_', ' ', $p->payment_method) . ')') . ' against ' . $inv->invoice_no, 'debit' => 0.0, 'credit' => round((float) $p->amount, 2)];
            }
        }
        usort($entries, fn ($a, $b) => [$a['at']->timestamp, $a['order'], $a['id']] <=> [$b['at']->timestamp, $b['order'], $b['id']]);

        $opening = 0.0;
        $lines = [];
        $balance = 0.0;
        foreach ($entries as $e) {
            $balance = round($balance + $e['debit'] - $e['credit'], 2);
            if ($e['at']->lt($start)) {
                $opening = $balance;
                continue;
            }
            $lines[] = ['date' => $e['at']->format('Y-m-d'), 'type' => $e['type'], 'reference' => $e['ref'], 'description' => $e['description'], 'debit' => $e['debit'], 'credit' => $e['credit'], 'balance' => $balance];
        }

        $today = $end->copy()->startOfDay();
        $outstanding = $invoices->map(function (Invoice $inv) use ($end, $today) {
            $paid = (float) $inv->payments->filter(fn ($p) => Carbon::parse($p->transaction_date)->lte($end))->sum('amount');
            $due = max(0, $inv->getTotal() - $paid);

            return ['invoice_no' => $inv->invoice_no, 'invoice_date' => $inv->invoice_date->format('Y-m-d'), 'due_date' => $inv->due_date?->format('Y-m-d'), 'total' => round($inv->getTotal(), 2),
                'paid' => round($paid, 2), 'balance' => round($due, 2), 'days_overdue' => max(0, Days::overdue($inv->due_date, $today))];
        })->filter(fn ($r) => $r['balance'] > 0)->sortByDesc('days_overdue')->values()->all();

        $company = Company::withoutGlobalScope('tenant')->find($companyId);

        return [
            'company'         => ['name' => $company?->legal_name ?: $company?->name, 'gstin' => $company?->gstin, 'address' => trim(implode(', ', array_filter([$company?->address_line1, $company?->city, $company?->state, $company?->pincode]))), 'phone' => $company?->phone],
            'customer'        => ['id' => $customer->id, 'name' => $customer->name, 'gstin' => $customer->gstin, 'address' => trim(implode(', ', array_filter([$customer->address_line1, $customer->city, $customer->state, $customer->pincode])))],
            'period'          => ['from' => $from, 'to' => $to],
            'opening_balance' => round($opening, 2),
            'lines'           => $lines,
            'total_debit'     => round(collect($lines)->sum('debit'), 2),
            'total_credit'    => round(collect($lines)->sum('credit'), 2),
            'closing_balance' => round($balance, 2),
            'outstanding'     => $outstanding,
        ];
    }
}
