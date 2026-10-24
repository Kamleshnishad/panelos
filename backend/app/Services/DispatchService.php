<?php

namespace App\Services;

use App\Models\Dispatch;
use App\Models\DispatchItem;
use App\Models\DispatchItemSize;
use App\Models\ProductionBatch;
use App\Models\QualityControl;
use App\Models\StockAllocation;
use App\Models\StockTransaction;
use Illuminate\Support\Facades\DB;

class DispatchService
{
    protected $stockService;
    protected $plans;

    public function __construct(StockService $stockService, DispatchPlanService $plans)
    {
        $this->stockService = $stockService;
        $this->plans = $plans;
    }

    /** What is left to dispatch for a batch, per order line and size row (FT-03). */
    public function planFor($batchId, $companyId = null): array
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $batch = ProductionBatch::where('company_id', $companyId)->with('order')->findOrFail($batchId);

        return $this->plans->plan($batch);
    }

    /**
     * The batch is `dispatched` only when every piece has gone out AND arrived: nothing left to send and no dispatch still
     * pending. Otherwise it stays `qc_passed` (ready to send more), and falls back to it when a delivered dispatch is cancelled.
     */
    public function syncBatchStatus(?ProductionBatch $batch): void
    {
        if (!$batch || !in_array($batch->status, ['qc_passed', 'completed', 'dispatched'], true)) {
            return;
        }

        $plan = $this->plans->plan($batch->loadMissing('order'));
        $pending = Dispatch::where('batch_id', $batch->id)->whereIn('status', ['pending', 'in_transit'])->exists();

        if ($plan['fully_dispatched'] && !$pending) {
            $batch->update(['status' => 'dispatched']);
        } elseif ($batch->status === 'dispatched') {
            $batch->update(['status' => 'qc_passed']);
        }
    }

    public function createDispatch($batchId, $data = [], $companyId = null)
    {
        return DB::transaction(function () use ($batchId, $data, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            // Locked for the rest of the transaction: two clicks on 'dispatch' must not both read the same pieces as still free
            // (the unique index that used to stop that is gone now that a batch may have several dispatches).
            $batch = ProductionBatch::where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($batchId);

            // Stricter precondition: an APPROVED 'pass' QC must exist for the batch.
            // The old check let 'completed' batches through, bypassing QC entirely.
            $qc = QualityControl::where('batch_id', $batch->id)
                ->where('company_id', $companyId)
                ->first();
            if (!$qc || $qc->status !== 'pass' || !$qc->approved_at) {
                throw new \Exception('Batch must have an approved-pass QC record before dispatch.');
            }
            if ($batch->status !== 'qc_passed' && $batch->status !== 'completed') {
                throw new \Exception('Batch must be qc_passed or completed before dispatch.');
            }

            // Which pieces this dispatch takes (everything still to send unless `items` says otherwise). Throws when there is
            // nothing left, so a batch can have several dispatches but never sends a piece twice.
            $lines = $this->plans->lines($batch->loadMissing('order'), $data['items'] ?? null);

            $dispatch = Dispatch::create([
                'company_id' => $companyId,
                'batch_id' => $batchId,
                'status' => 'pending',
                'dispatch_date' => $data['dispatch_date'] ?? now(),
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                // Actual freight, settled now rather than quoted months ago (PB-021).
                'transport_amount' => $data['transport_amount'] ?? 0,
                'transporter_name' => $data['transporter_name'] ?? null,
                'vehicle_no' => $data['vehicle_no'] ?? null,
                'notes' => $data['notes'] ?? null
            ]);

            foreach ($lines as $line) {
                $oi = $line['item'];
                $item = DispatchItem::create([
                    'dispatch_id'   => $dispatch->id,
                    'order_item_id' => $oi->id,
                    'panel_type_id' => $oi->panel_type_id,
                    'quantity'      => $line['quantity'],
                    'unit_price'    => $oi->rate_per_sqm ?: $oi->unit_price,
                    'amount'        => $line['amount'],
                    'created_at'    => now(),
                ]);
                foreach ($line['sizes'] as $sz) {
                    DispatchItemSize::create([
                        'dispatch_item_id'   => $item->id,
                        'order_item_size_id' => $sz['size']->id,
                        'length_mm'          => $sz['size']->length_mm,
                        'width_mm'           => $sz['size']->width_mm,
                        'nos'                => $sz['nos'],
                        'sqm'                => $sz['sqm'],
                        'amount'             => $sz['amount'],
                        'created_at'         => now(),
                    ]);
                }
            }

            return $dispatch->load('items.panelType', 'items.sizes', 'batch');
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
            $this->syncBatchStatus($dispatch->batch);

            // WhatsApp/SMS notification — silent on failure
            try {
                app(\App\Services\NotificationDispatchService::class)->dispatchDone($dispatch);
            } catch (\Throwable) {}

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

            // Its pieces become available again; a batch that had been fully delivered is no longer.
            $this->syncBatchStatus($dispatch->batch);

            return $dispatch;
        });
    }

    public function getDispatchDetails($dispatchId, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        return Dispatch::where('company_id', $companyId)
            ->with('batch', 'items.panelType', 'items.sizes', 'allocations')
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

        $allowedFields = ['customer_address', 'expected_delivery_date', 'tracking_number', 'notes',
            // Freight is renegotiated until the vehicle is confirmed, so it stays
            // editable on the dispatch right up to the point of invoicing.
            'transport_amount', 'transporter_name', 'vehicle_no'];
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
            'transporter' => $dispatch->transporter_name,
            'vehicle_no' => $dispatch->vehicle_no,
            'transport_amount' => (float) $dispatch->transport_amount,
            'items' => $dispatch->items->map(function ($item) {
                return [
                    'panel_type' => $item->panelType?->name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                    'sizes' => $item->sizes->map(fn ($z) => ['length_mm' => $z->length_mm, 'width_mm' => $z->width_mm, 'nos' => $z->nos, 'sqm' => $z->sqm])->values(),
                ];
            }),
            'total' => $dispatch->total_amount,
            'total_items' => $dispatch->total_items,
            'notes' => $dispatch->notes
        ];
    }
}
