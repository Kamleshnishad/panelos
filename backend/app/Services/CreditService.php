<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;

/**
 * Credit-control: computes a customer's live outstanding receivable and decides
 * whether a new order would push them past their credit limit.
 *
 * Outstanding is computed live (the customers.outstanding_balance column is
 * stale and never updated). It sums unpaid invoice balances (total - payments)
 * for invoices linked to the customer via their orders.
 */
class CreditService
{
    /** Live outstanding receivable for a customer (unpaid invoice balances). */
    public function outstanding(int $companyId, int $customerId): float
    {
        $orderIds = Order::where('company_id', $companyId)
            ->where('customer_id', $customerId)->pluck('id');

        if ($orderIds->isEmpty()) return 0.0;

        $invoices = Invoice::where('company_id', $companyId)
            ->whereIn('order_id', $orderIds)
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->withSum('payments', 'amount')
            ->get();

        return (float) $invoices->sum(function ($inv) {
            $paid = (float) ($inv->payments_sum_amount ?? 0);
            return max(0, (float) $inv->total_amount - $paid);
        });
    }

    /**
     * Credit status for a customer, optionally including a prospective new order.
     * credit_limit <= 0 means "no limit set" → never blocks.
     */
    public function status(Customer $customer, float $newOrderTotal = 0.0): array
    {
        $limit       = (float) $customer->credit_limit;
        $outstanding = $this->outstanding($customer->company_id, $customer->id);
        $wouldBe     = $outstanding + max(0, $newOrderTotal);
        $hasLimit    = $limit > 0;

        return [
            'credit_limit'   => round($limit, 2),
            'has_limit'      => $hasLimit,
            'outstanding'    => round($outstanding, 2),
            'new_order'      => round(max(0, $newOrderTotal), 2),
            'would_be'       => round($wouldBe, 2),
            'available'      => $hasLimit ? round($limit - $outstanding, 2) : null,
            'within_limit'   => !$hasLimit || $wouldBe <= $limit,
            'over_by'        => $hasLimit ? round(max(0, $wouldBe - $limit), 2) : 0.0,
        ];
    }
}
