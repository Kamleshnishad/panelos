<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function recordPayment($invoiceId, $amount, $paymentMethod = 'bank_transfer', $referenceNo = null, $companyId = null)
    {
        return DB::transaction(function () use ($invoiceId, $amount, $paymentMethod, $referenceNo, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            // Row lock serializes concurrent manual payments on the same invoice,
            // so the remaining-due check below sees earlier committed payments.
            $invoice = Invoice::where('company_id', $companyId)->lockForUpdate()->findOrFail($invoiceId);

            if ($invoice->status === 'cancelled') {
                throw new \Exception('Cannot record payment for cancelled invoice');
            }

            if ($invoice->status === 'draft') {
                throw new \Exception('Cannot record payment for draft invoice');
            }

            $remainingDue = $this->calculateRemainingDue($invoiceId);

            if ($amount > $remainingDue) {
                throw new \Exception('Payment amount exceeds remaining due amount of ' . $remainingDue);
            }

            $payment = PaymentTransaction::create([
                'company_id' => $companyId,
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'reference_no' => $referenceNo,
                'transaction_date' => now(),
                'created_by_user_id' => auth()->id()
            ]);

            $newRemaining = $this->calculateRemainingDue($invoiceId);

            if ($newRemaining <= 0) {
                $invoice->update(['status' => 'paid', 'paid_date' => now()]);

                // Mark reminder as paid
                try {
                    $reminderService = app(PaymentReminderService::class);
                    $reminderService->markReminderAsPaid($invoiceId);
                } catch (\Exception $e) {
                    Log::error('Failed to mark reminder as paid', ['error' => $e->getMessage()]);
                }
            }

            return $payment;
        });
    }

    public function getPaymentHistory($invoiceId, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        return PaymentTransaction::where('company_id', $companyId)
            ->where('invoice_id', $invoiceId)
            ->orderBy('transaction_date', 'desc')
            ->get();
    }

    public function calculateRemainingDue($invoiceId)
    {
        $invoice = Invoice::findOrFail($invoiceId);

        $total = $invoice->subtotal + ($invoice->taxCalculation->tax_amount ?? 0);
        $paid = PaymentTransaction::where('invoice_id', $invoiceId)->sum('amount');

        // Rounded: binary float subtraction otherwise leaks values like
        // 64000.119999999995 into the API and into "amount exceeds due" checks.
        return round(max(0, $total - $paid), 2);
    }

    public function getPaymentStatus($invoiceId, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);
        $total = $invoice->subtotal + ($invoice->taxCalculation->tax_amount ?? 0);
        $paid = PaymentTransaction::where('invoice_id', $invoiceId)->sum('amount');
        $remaining = round(max(0, $total - $paid), 2);

        return [
            'invoice_id' => $invoiceId,
            'total_amount' => round((float) $total, 2),
            'paid_amount' => round((float) $paid, 2),
            'remaining_due' => $remaining,
            'payment_percentage' => $total > 0 ? round(($paid / $total) * 100, 2) : 0,
            'status' => $invoice->status,
            'is_overdue' => $invoice->due_date < now() && $remaining > 0,
            // Carbon diffInDays is signed in this codebase; only count days when
            // actually past due, and read past->now() so the result is positive.
            'days_overdue' => ($invoice->due_date < now() && $remaining > 0)
                ? (int) floor($invoice->due_date->diffInDays(now()))
                : 0,
        ];
    }

    public function issueReminder($invoiceId, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $status = $this->getPaymentStatus($invoiceId, $companyId);

        if ($status['remaining_due'] <= 0) {
            throw new \Exception('Invoice is already fully paid');
        }

        return [
            'invoice_id' => $invoiceId,
            'message' => 'Payment reminder issued',
            'remaining_due' => $status['remaining_due'],
            'due_date' => Invoice::find($invoiceId)->due_date,
            'reminder_sent_at' => now()
        ];
    }

    public function writeOff($invoiceId, $amount, $reason, $companyId = null)
    {
        return DB::transaction(function () use ($invoiceId, $amount, $reason, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);
            $remaining = $this->calculateRemainingDue($invoiceId);

            if ($amount > $remaining) {
                throw new \Exception('Write-off amount exceeds remaining due');
            }

            PaymentTransaction::create([
                'company_id' => $companyId,
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'payment_method' => 'write_off',
                'reference_no' => 'WRITEOFF-' . $reason,
                'transaction_date' => now(),
                'created_by_user_id' => auth()->id()
            ]);

            $newRemaining = $this->calculateRemainingDue($invoiceId);

            if ($newRemaining <= 0) {
                $invoice->update(['status' => 'paid', 'paid_date' => now()]);
            }

            return [
                'message' => 'Write-off recorded',
                'amount' => $amount,
                'reason' => $reason,
                'remaining_after_writeoff' => $newRemaining
            ];
        });
    }

    public function reconcilePayment($invoiceId, $paidAmount, $referenceNo = null, $companyId = null)
    {
        return DB::transaction(function () use ($invoiceId, $paidAmount, $referenceNo, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);

            if ($invoice->status === 'draft') {
                $invoice->update(['status' => 'sent']);
            }

            $this->recordPayment($invoiceId, $paidAmount, 'bank_transfer', $referenceNo, $companyId);

            return $this->getPaymentStatus($invoiceId, $companyId);
        });
    }

    public function getUnpaidInvoices($companyId = null, $page = 1, $perPage = 20)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled', 'paid'])
            // 'payments' is not optional here: Invoice::$appends exposes
            // remaining_due, whose accessor sums $this->payments — so every
            // toArray() lazy-loads payments and costs a query per row unless
            // the relation is already there.
            ->with('items', 'taxCalculation', 'payments', 'dispatch.batch.order.customer', 'order.customer')
            ->orderBy('due_date', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        // One grouped query for paid-per-invoice instead of getPaymentStatus()
        // per row, which re-fetched the invoice and summed its payments every
        // time. Receivables are exactly what grows when business is good, so
        // the old shape got slower precisely when it mattered most.
        $paid = PaymentTransaction::whereIn('invoice_id', collect($invoices->items())->pluck('id'))
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(amount) as paid')
            ->pluck('paid', 'invoice_id');

        $now = now();
        $invoices->getCollection()->transform(function ($invoice) use ($paid, $now) {
            $total     = round((float) $invoice->total_amount, 2);
            $paidSoFar = round((float) ($paid[$invoice->id] ?? 0), 2);
            $remaining = round(max(0, $total - $paidSoFar), 2);
            $overdue   = $invoice->due_date && $invoice->due_date < $now && $remaining > 0;

            return array_merge($invoice->toArray(), [
                'payment_status' => [
                    'invoice_id'         => $invoice->id,
                    'total_amount'       => $total,
                    'paid_amount'        => $paidSoFar,
                    'remaining_due'      => $remaining,
                    'payment_percentage' => $total > 0 ? round(($paidSoFar / $total) * 100, 2) : 0,
                    'status'             => $invoice->status,
                    'is_overdue'         => (bool) $overdue,
                    'days_overdue'       => $overdue ? (int) floor($invoice->due_date->diffInDays($now)) : 0,
                ],
            ]);
        });

        return $invoices;
    }
}
