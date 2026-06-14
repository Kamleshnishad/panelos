<?php

namespace App\Services;

use App\Models\Dispatch;
use App\Models\DispatchItem;
use App\Models\ProductionBatch;
use App\Models\StockAllocation;
use App\Models\StockTransaction;
use Illuminate\Support\Facades\DB;

class DispatchService
{
    protected $stockService;

    public function __construct(StockService $stockService)
    {
        $this->stockService = $stockService;
    }

    public function createDispatch($batchId, $data = [], $companyId = null)
    {
        return DB::transaction(function () use ($batchId, $data, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            $batch = ProductionBatch::where('company_id', $companyId)
                ->findOrFail($batchId);

            if ($batch->status !== 'qc_passed' && $batch->status !== 'completed') {
                throw new \Exception('Batch must be completed or passed QC before dispatch');
            }

            if (Dispatch::where('batch_id', $batchId)->exists()) {
                throw new \Exception('Batch has already been dispatched');
            }

            $dispatch = Dispatch::create([
                'company_id' => $companyId,
                'batch_id' => $batchId,
                'status' => 'pending',
                'dispatch_date' => $data['dispatch_date'] ?? now(),
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'notes' => $data['notes'] ?? null
            ]);

            // Copy panel items from the batch's underlying order
            $orderItems = $batch->orderItems()->get();
            foreach ($orderItems as $item) {
                DispatchItem::create([
                    'dispatch_id'   => $dispatch->id,
                    'panel_type_id' => $item->panel_type_id,
                    'quantity'      => $item->total_sqm ?: $item->quantity,
                    'unit_price'    => $item->rate_per_sqm ?: $item->unit_price,
                    'amount'        => $item->amount,
                    'created_at'    => now(),
                ]);
            }

            return $dispatch->load('items.panelType', 'batch');
        });
    }

    public function addDispatchItem($dispatchId, $panelTypeId, $quantity, $unitPrice, $companyId = null)
    {
        return DB::transaction(function () use ($dispatchId, $panelTypeId, $quantity, $unitPrice, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            $dispatch = Dispatch::where('company_id', $companyId)
                ->findOrFail($dispatchId);

            $amount = $quantity * $unitPrice;

            $item = DispatchItem::create([
                'dispatch_id' => $dispatchId,
                'panel_type_id' => $panelTypeId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'amount' => $amount,
                'created_at' => now()
            ]);

            return $item;
        });
    }

    /**
     * Raw coil is now consumed at PRODUCTION (run start) via
     * ProductionMaterialService, not at dispatch — so dispatch no longer
     * allocates/reserves coil stock (that would double-count). Kept as a no-op
     * so the existing /dispatches/{id}/allocate endpoint stays valid.
     */
    public function allocateStockForDispatch($dispatchId, $companyId = null)
    {
        return [];
    }

    public function completeDispatch($dispatchId, $data = [], $companyId = null)
    {
        return DB::transaction(function () use ($dispatchId, $data, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            $dispatch = Dispatch::where('company_id', $companyId)
                ->with('batch', 'items.panelType')
                ->findOrFail($dispatchId);

            // Raw material was already consumed at production (run start), so
            // dispatch no longer requires coil allocation or deducts coil here.
            // Mark any legacy allocations used (harmless if none).
            $dispatch->allocations()
                ->where('status', 'allocated')
                ->each(fn($allocation) => $allocation->markAsUsed());

            $dispatch->markAsCompleted($data['actual_delivery_date'] ?? now());

            return $dispatch;
        });
    }

    public function cancelDispatch($dispatchId, $companyId = null)
    {
        return DB::transaction(function () use ($dispatchId, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            $dispatch = Dispatch::where('company_id', $companyId)
                ->findOrFail($dispatchId);

            $dispatch->cancel();

            // Revert batch status if needed
            if ($dispatch->batch) {
                $dispatch->batch->update(['status' => 'qc_passed']);
            }

            return $dispatch;
        });
    }

    public function getDispatchDetails($dispatchId, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        return Dispatch::where('company_id', $companyId)
            ->with('batch', 'items.panelType', 'allocations')
            ->findOrFail($dispatchId);
    }

    public function listDispatches($filters = [], $companyId = null, $page = 1, $perPage = 20)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $query = Dispatch::where('company_id', $companyId)
            ->with('batch', 'items');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $query->where('dispatch_no', 'like', "%{$filters['search']}%");
        }

        if (!empty($filters['from_date'])) {
            $query->whereDate('dispatch_date', '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $query->whereDate('dispatch_date', '<=', $filters['to_date']);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function updateDispatch($dispatchId, $data, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $dispatch = Dispatch::where('company_id', $companyId)
            ->findOrFail($dispatchId);

        $allowedFields = ['customer_address', 'expected_delivery_date', 'tracking_number', 'notes'];
        $updateData = collect($data)->only($allowedFields)->toArray();

        if (empty($updateData)) {
            return $dispatch;
        }

        $dispatch->update($updateData);

        return $dispatch;
    }

    public function generateChallan($dispatchId, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $dispatch = $this->getDispatchDetails($dispatchId, $companyId);

        return [
            'dispatch_no' => $dispatch->dispatch_no,
            'dispatch_date' => $dispatch->dispatch_date->format('Y-m-d'),
            'batch_no' => $dispatch->batch?->batch_no,
            'customer' => $dispatch->batch?->order?->customer?->name,
            'address' => $dispatch->customer_address,
            'tracking' => $dispatch->tracking_number,
            'items' => $dispatch->items->map(function ($item) {
                return [
                    'panel_type' => $item->panelType?->name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount
                ];
            }),
            'total' => $dispatch->total_amount,
            'total_items' => $dispatch->total_items,
            'notes' => $dispatch->notes
        ];
    }
}
