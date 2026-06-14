<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItemSize;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function createFromQuotation(Quotation $quotation): Order
    {
        if ($quotation->status !== 'accepted') {
            throw new \Exception('Quotation must be accepted to create an order.');
        }

        if ($quotation->orders()->exists()) {
            throw new \Exception('An order already exists for this quotation.');
        }

        return DB::transaction(function () use ($quotation) {
            $quotation->load('items.sizes', 'items.panelType', 'accessories', 'customer');

            $order = Order::create([
                'company_id'              => $quotation->company_id,
                'quotation_id'            => $quotation->id,
                'order_no'                => $this->generateOrderNumber($quotation->company_id),
                'customer_id'             => $quotation->customer_id,
                'status'                  => 'pending',
                'subtotal'                => $quotation->subtotal,
                'tax_amount'              => $quotation->tax_amount,
                'total_amount'            => $quotation->total_amount,
                'discount_amount'         => $quotation->discount_amount,
                'taxable_amount'          => $quotation->taxable_amount,
                'cgst_amount'             => $quotation->cgst_amount,
                'sgst_amount'             => $quotation->sgst_amount,
                'igst_amount'             => $quotation->igst_amount,
                'is_inter_state'          => $quotation->is_inter_state,
                'transport_fixed'         => $quotation->transport_fixed,
                'transport_amount'        => $quotation->transport_amount,
                'advance_pct'             => $quotation->advance_pct,
                'advance_amount'          => $quotation->advance_amount,
                'balance_amount'          => $quotation->balance_amount,
                'total_sqm'               => $quotation->total_sqm,
                'project_name'            => $quotation->project_name,
                'project_location'        => $quotation->project_location,
                'quality_grade'           => $quotation->quality_grade,
                'order_date'              => now()->toDateString(),
                'expected_delivery_date'  => now()->addDays(14)->toDateString(),
                'notes'                   => $quotation->notes,
            ]);

            // Snapshot every panel row with full BOQ spec
            foreach ($quotation->items as $item) {
                $orderItem = $order->items()->create([
                    'panel_type_id'          => $item->panel_type_id,
                    'thickness'              => $item->thickness,
                    'density_type'           => $item->density_type,
                    'density_kgm3'           => $item->density_kgm3,
                    'top_skin_material'      => $item->top_skin_material,
                    'top_skin_thickness'     => $item->top_skin_thickness,
                    'top_color'              => $item->top_color,
                    'top_surface'            => $item->top_surface,
                    'bottom_skin_material'   => $item->bottom_skin_material,
                    'bottom_skin_thickness'  => $item->bottom_skin_thickness,
                    'bottom_color'           => $item->bottom_color,
                    'guard_film'             => $item->guard_film,
                    'cello_tap'              => $item->cello_tap,
                    'hsn_code'               => $item->hsn_code,
                    'total_sqm'              => $item->total_sqm,
                    'rate_per_sqm'           => $item->rate_per_sqm,
                    'amount'                 => $item->amount,
                    'quantity'               => $item->quantity,
                    'unit_price'             => $item->unit_price,
                    'sort_order'             => $item->sort_order,
                ]);

                // Snapshot each size row
                foreach ($item->sizes as $size) {
                    OrderItemSize::create([
                        'order_item_id' => $orderItem->id,
                        'length_mm'     => $size->length_mm,
                        'width_mm'      => $size->width_mm,
                        'nos'           => $size->nos,
                        'sqm'           => $size->sqm,
                        'rate_per_sqm'  => $size->rate_per_sqm,
                        'amount'        => $size->amount,
                        'sort_order'    => $size->sort_order,
                    ]);
                }
            }

            return $order->load('items.sizes', 'items.panelType', 'customer', 'quotation');
        });
    }

    public function update(Order $order, array $data): Order
    {
        $allowed = ['expected_delivery_date', 'notes', 'status'];
        $order->update(array_intersect_key($data, array_flip($allowed)));
        return $order->fresh();
    }

    public function list(int $companyId, array $filters = [])
    {
        $query = Order::where('company_id', $companyId)
            ->with('customer', 'items', 'quotation');

        if (!empty($filters['status']))      $query->where('status', $filters['status']);
        if (!empty($filters['customer_id'])) $query->where('customer_id', $filters['customer_id']);
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('order_no', 'like', "%{$search}%")
                  ->orWhere('project_name', 'like', "%{$search}%")
                  ->orWhereHas('customer', fn($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $sortBy    = $filters['sort_by']    ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query;
    }

    public function getDetails(Order $order): array
    {
        $order->load('items.sizes', 'items.panelType', 'customer', 'quotation', 'batches', 'company');

        return array_merge($order->toArray(), [
            'items' => $order->items->map(function ($item) {
                return array_merge($item->toArray(), [
                    'panel_type' => $item->panelType?->toArray(),
                    'sizes'      => $item->sizes->toArray(),
                ]);
            })->toArray(),
        ]);
    }

    private function generateOrderNumber(int $companyId): string
    {
        $company = DB::table('companies')->find($companyId);
        $prefix  = $company->order_prefix ?? 'ORD';

        $today   = now();
        $fyYear  = $today->month >= 4 ? $today->year : $today->year - 1;
        $fyStart = \Carbon\Carbon::create($fyYear, 4, 1);
        $fyEnd   = \Carbon\Carbon::create($fyYear + 1, 3, 31, 23, 59, 59);

        $last = Order::where('company_id', $companyId)
            ->whereBetween('created_at', [$fyStart, $fyEnd])
            ->whereRaw("order_no REGEXP ?", ['^' . preg_quote($prefix, '/') . '-[0-9]{4}-[0-9]+$'])
            ->orderByDesc('id')
            ->value('order_no');

        $seq = 1;
        if ($last) {
            $parts = explode('-', $last);
            $lastNum = (int) end($parts);
            if ($lastNum > 0) $seq = $lastNum + 1;
        }

        return sprintf('%s-%d-%03d', $prefix, $fyYear + 1, $seq);
    }
}
