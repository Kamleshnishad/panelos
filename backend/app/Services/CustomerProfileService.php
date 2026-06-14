<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductionBatch;
use App\Models\Quotation;
use Illuminate\Support\Carbon;

/**
 * Customer 360 — aggregates everything related to a customer (quotations,
 * orders, invoices, dispatches, leads) plus KPIs, repeat-order frequency and an
 * RFM segment. Read-only. invoices/dispatches link indirectly (via order/batch).
 */
class CustomerProfileService
{
    public function profile(Customer $customer): array
    {
        $cid = $customer->company_id;
        $custId = $customer->id;

        // ── Orders (direct) — the basis for business value + repeat frequency ──
        $orders = Order::where('company_id', $cid)->where('customer_id', $custId)
            ->where('status', '!=', 'cancelled')
            ->orderBy('order_date')->get();
        $orderIds = $orders->pluck('id')->all();

        $orderCount = $orders->count();
        $totalBiz   = (float) $orders->sum('total_amount');
        $totalSqm   = (float) $orders->sum('total_sqm');
        $aov        = $orderCount > 0 ? $totalBiz / $orderCount : 0;
        $first      = $orders->first()?->order_date;
        $last       = $orders->last()?->order_date;
        $spanDays   = ($first && $last) ? $first->diffInDays($last) : 0;
        $avgGap     = $orderCount > 1 ? (int) round($spanDays / ($orderCount - 1)) : null;
        $perYear    = $spanDays > 7 ? round($orderCount / ($spanDays / 365), 1) : ($orderCount ?: 0);
        $recency    = $last ? (int) $last->diffInDays(now()) : null;

        // ── Quotations (direct) ──
        $quotations = Quotation::where('company_id', $cid)->where('customer_id', $custId)
            ->orderByDesc('id')->get();
        $quoteCount = $quotations->count();
        $quoteByStatus = $quotations->groupBy('status')->map->count();

        // ── Invoices (via order_id) ──
        $invoices = collect();
        if ($orderIds) {
            $invoices = Invoice::where('company_id', $cid)->whereIn('order_id', $orderIds)
                ->orderByDesc('id')->get();
        }
        $invoiced    = (float) $invoices->sum('total_amount');
        $outstanding = (float) $invoices->whereNotIn('status', ['paid', 'cancelled'])->sum('total_amount');

        // ── Dispatches (via batch → order) ──
        $dispatches = collect();
        if ($orderIds) {
            $batchIds = ProductionBatch::where('company_id', $cid)->whereIn('order_id', $orderIds)->pluck('id')->all();
            if ($batchIds) {
                $dispatches = Dispatch::where('company_id', $cid)->whereIn('batch_id', $batchIds)
                    ->orderByDesc('id')->get();
            }
        }

        // ── Leads (direct) ──
        $leads = Lead::where('company_id', $cid)->where('customer_id', $custId)->orderByDesc('id')->get();

        // ── Top panel types ordered (product frequency) ──
        $topProducts = [];
        if ($orderIds) {
            $topProducts = OrderItem::whereIn('order_id', $orderIds)
                ->with('panelType')
                ->get()
                ->groupBy('panel_type_id')
                ->map(function ($rows) {
                    return [
                        'name'  => $rows->first()->panelType->name ?? ('Panel #' . $rows->first()->panel_type_id),
                        'times' => $rows->count(),
                        'sqm'   => round((float) $rows->sum('total_sqm'), 2),
                    ];
                })
                ->sortByDesc('times')->take(6)->values()->all();
        }

        // ── Monthly orders (last 12 months) for a sparkline ──
        $monthly = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = now()->copy()->subMonths($i);
            $monthly[] = [
                'label' => $m->format('M'),
                'count' => $orders->filter(fn ($o) => $o->order_date && $o->order_date->isSameMonth($m))->count(),
            ];
        }

        return [
            'customer' => $customer,
            'kpis' => [
                'total_business' => round($totalBiz, 2),
                'order_count'    => $orderCount,
                'avg_order_value'=> round($aov, 2),
                'outstanding'    => round($outstanding, 2),
                'invoiced'       => round($invoiced, 2),
                'total_sqm'      => round($totalSqm, 2),
                'quote_count'    => $quoteCount,
                'lead_count'     => $leads->count(),
                'dispatch_count' => $dispatches->count(),
            ],
            'repeat' => [
                'is_repeat'    => $orderCount > 1,
                'avg_gap_days' => $avgGap,
                'orders_per_year' => $perYear,
                'first_order'  => optional($first)->toDateString(),
                'last_order'   => optional($last)->toDateString(),
                'recency_days' => $recency,
                'frequency'    => $orderCount,
                'monetary'     => round($totalBiz, 2),
                'segment'      => $this->segment($orderCount, $recency),
            ],
            'quote_by_status' => $quoteByStatus,
            'top_products'    => $topProducts,
            'monthly_orders'  => $monthly,
            'quotations'      => $quotations->map(fn ($q) => $this->qRow($q)),
            'orders'          => $orders->sortByDesc('id')->values()->map(fn ($o) => $this->oRow($o)),
            'invoices'        => $invoices->map(fn ($i) => $this->iRow($i)),
            'dispatches'      => $dispatches->map(fn ($d) => $this->dRow($d)),
            'leads'           => $leads->map(fn ($l) => $this->lRow($l)),
        ];
    }

    private function segment(int $count, ?int $recency): string
    {
        if ($count === 0) return 'Prospect';
        if ($count === 1) return 'New';
        if ($recency === null) return 'Loyal';
        if ($recency <= 90 && $count >= 4) return 'Champion';
        if ($recency <= 180) return 'Loyal';
        if ($recency <= 365) return 'At Risk';
        return 'Dormant';
    }

    private function qRow($q): array
    {
        return ['id' => $q->id, 'no' => $q->quotation_no, 'status' => $q->status,
            'date' => optional($q->quoted_on)->toDateString(), 'total' => (float) $q->total_amount];
    }
    private function oRow($o): array
    {
        return ['id' => $o->id, 'no' => $o->order_no, 'status' => $o->status,
            'date' => optional($o->order_date)->toDateString(), 'total' => (float) $o->total_amount,
            'sqm' => (float) $o->total_sqm];
    }
    private function iRow($i): array
    {
        return ['id' => $i->id, 'no' => $i->invoice_no, 'status' => $i->status,
            'date' => optional($i->invoice_date)->toDateString(), 'total' => (float) $i->total_amount];
    }
    private function dRow($d): array
    {
        return ['id' => $d->id, 'no' => $d->dispatch_no, 'status' => $d->status,
            'date' => optional($d->dispatch_date)->toDateString()];
    }
    private function lRow($l): array
    {
        return ['id' => $l->id, 'no' => $l->lead_no, 'status' => $l->status, 'source' => $l->source,
            'date' => optional($l->created_at)->toDateString()];
    }
}
