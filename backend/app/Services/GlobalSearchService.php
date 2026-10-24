<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\ProductionBatch;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Inr;

/**
 * One search box for the header (UX-04). Finds the records people phone each other about by number, customer or
 * project, and returns only the kinds the user's role may open. Tenant scoping is the models' own global scope.
 * Amounts shown are the same ones the list screens show; nothing here exposes cost or margin.
 */
class GlobalSearchService
{
    public const MIN_LENGTH = 2;
    public const PER_GROUP = 5;

    /** @return list<array{type:string,label:string,section:string,items:list<array{id:int,title:string,subtitle:string,meta:string}>}> */
    public function search(User $user, string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return [];
        }
        $like = '%' . addcslashes($term, '\\%_') . '%';
        $cid = (int) $user->company_id;   // explicit, not only the model scope: a platform super admin bypasses that scope

        $sources = [
            ['quotations', 'Quotations', 'quotations.view', fn () => $this->quotations($like, $cid)],
            ['orders', 'Orders', 'orders.view', fn () => $this->orders($like, $cid)],
            ['invoices', 'Invoices', 'invoices.view', fn () => $this->invoices($like, $cid)],
            ['customers', 'Customers', 'customers.view', fn () => $this->customers($like, $cid)],
            ['batches', 'Production batches', 'production.view', fn () => $this->batches($like, $cid)],
            ['dispatches', 'Dispatches', 'dispatch.view', fn () => $this->dispatches($like, $cid)],
        ];

        $groups = [];
        foreach ($sources as [$section, $label, $permission, $run]) {
            if (!$user->hasPermission($permission)) {
                continue;
            }
            $items = $run();
            if ($items) {
                $groups[] = ['type' => $section, 'label' => $label, 'section' => $section, 'items' => $items];
            }
        }

        return $groups;
    }

    private function item(int $id, string $title, array $subtitle, array $meta = []): array
    {
        return [
            'id'       => $id,
            'title'    => $title,
            'subtitle' => implode(' · ', array_filter($subtitle, fn ($v) => $v !== null && $v !== '')),
            'meta'     => implode(' · ', array_filter($meta, fn ($v) => $v !== null && $v !== '')),
        ];
    }

    private function money($amount): string
    {
        return '₹ ' . Inr::fmt($amount);
    }

    private function status(?string $status): string
    {
        return ucfirst(str_replace('_', ' ', (string) $status));
    }

    private function quotations(string $like, int $cid): array
    {
        return Quotation::with('customer:id,name')->where('company_id', $cid)
            ->where(fn ($q) => $q->where('quotation_no', 'like', $like)->orWhere('project_name', 'like', $like)
                ->orWhere('project_location', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)))
            ->orderByDesc('id')->limit(self::PER_GROUP)->get()
            ->map(fn ($q) => $this->item($q->id, $q->quotation_no, [$q->customer?->name, $q->project_name], [$this->status($q->status), $this->money($q->total_amount)]))
            ->all();
    }

    private function orders(string $like, int $cid): array
    {
        return Order::with('customer:id,name')->where('company_id', $cid)
            ->where(fn ($q) => $q->where('order_no', 'like', $like)->orWhere('project_name', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)))
            ->orderByDesc('id')->limit(self::PER_GROUP)->get()
            ->map(fn ($o) => $this->item($o->id, $o->order_no, [$o->customer?->name, $o->project_name], [$this->status($o->status), $this->money($o->total_amount)]))
            ->all();
    }

    private function invoices(string $like, int $cid): array
    {
        return Invoice::with('order.customer:id,name')->where('company_id', $cid)
            ->where(fn ($q) => $q->where('invoice_no', 'like', $like)
                ->orWhereHas('order', fn ($o) => $o->where('project_name', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like))))
            ->orderByDesc('id')->limit(self::PER_GROUP)->get()
            ->map(fn ($i) => $this->item($i->id, $i->invoice_no, [$i->order?->customer?->name, $i->order?->project_name], [$this->status($i->status), $this->money($i->total_amount)]))
            ->all();
    }

    private function customers(string $like, int $cid): array
    {
        return Customer::where('company_id', $cid)->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('phone', 'like', $like)
                ->orWhere('email', 'like', $like)->orWhere('gstin', 'like', $like)->orWhere('city', 'like', $like))
            ->orderBy('name')->limit(self::PER_GROUP)->get()
            ->map(fn ($c) => $this->item($c->id, $c->name, [$c->code, $c->city], [$c->phone]))
            ->all();
    }

    private function batches(string $like, int $cid): array
    {
        return ProductionBatch::with('order:id,order_no,project_name')->where('company_id', $cid)
            ->where(fn ($q) => $q->where('batch_no', 'like', $like)
                ->orWhereHas('order', fn ($o) => $o->where('order_no', 'like', $like)->orWhere('project_name', 'like', $like)))
            ->orderByDesc('id')->limit(self::PER_GROUP)->get()
            ->map(fn ($b) => $this->item($b->id, $b->batch_no, [$b->order?->order_no, $b->order?->project_name], [$this->status($b->status)]))
            ->all();
    }

    private function dispatches(string $like, int $cid): array
    {
        return Dispatch::with('batch:id,batch_no')->where('company_id', $cid)
            ->where(fn ($q) => $q->where('dispatch_no', 'like', $like)->orWhere('vehicle_no', 'like', $like)
                ->orWhere('transporter_name', 'like', $like)->orWhere('tracking_number', 'like', $like))
            ->orderByDesc('id')->limit(self::PER_GROUP)->get()
            ->map(fn ($d) => $this->item($d->id, $d->dispatch_no, [$d->batch?->batch_no, $d->vehicle_no, $d->transporter_name], [$this->status($d->status)]))
            ->all();
    }
}
