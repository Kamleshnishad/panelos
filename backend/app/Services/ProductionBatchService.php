<?php

namespace App\Services;

use App\Models\ProductionBatch;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class ProductionBatchService
{
    public function __construct(private ProductionMaterialService $materialService) {}

    /**
     * Create production batch from order items
     */
    public function createFromOrder(Order $order, array $data): ProductionBatch
    {
        return DB::transaction(function () use ($order, $data) {
            $order->load('items');

            $batchNo = $this->generateBatchNumber($order->company_id);

            $batch = ProductionBatch::create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'batch_no' => $batchNo,
                'status' => 'draft',
                'planned_quantity' => $data['planned_quantity'] ?? $this->calculateTotalQuantity($order),
                'completed_quantity' => 0,
                'notes' => $data['notes'] ?? null,
            ]);

            // Claim the order the moment it has a batch, whether or not that
            // batch belongs to a run. Only ProductionRunService used to do this,
            // so an order produced through the standalone path stayed 'pending'
            // forever — the planner kept offering it as work to do, and a second
            // run could be built from it, producing the job twice and issuing
            // raw material twice.
            if ($order->status === 'pending') {
                $order->update(['status' => 'in_production']);
            }

            return $batch;
        });
    }

    /**
     * List batches for company
     */
    public function list(int $companyId, array $filters = [])
    {
        $query = ProductionBatch::where('company_id', $companyId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['order_id'])) {
            $query->where('order_id', $filters['order_id']);
        }

        if (isset($filters['search'])) {
            $query->where('batch_no', 'like', "%{$filters['search']}%");
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->with('order', 'items', 'stageLogs', 'qualityControl');
    }

    /**
     * Get batch details
     */
    public function getDetails(ProductionBatch $batch): ProductionBatch
    {
        return $batch->load('order', 'items', 'stageLogs', 'qualityControl');
    }

    /**
     * Update batch
     */
    public function update(ProductionBatch $batch, array $data): ProductionBatch
    {
        $batch->update([
            'planned_quantity' => $data['planned_quantity'] ?? $batch->planned_quantity,
            'notes' => $data['notes'] ?? $batch->notes,
        ]);

        return $batch->fresh();
    }

    /**
     * Start production for batch
     */
    public function startProduction(ProductionBatch $batch, bool $force = false): ProductionBatch
    {
        return DB::transaction(function () use ($batch, $force) {
            // Lock + recheck: two concurrent starts must not both issue material.
            $batch = ProductionBatch::where('id', $batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== 'draft') {
                throw new \Exception('Cannot start production for batch not in draft status');
            }

            // A production run issues material for its child batches up front
            // (ProductionRunService::start). A standalone batch (no run_id) has
            // no run to do that, so it issues its own material here instead —
            // previously this unconditionally threw, so no standalone batch
            // could ever be started (C1 fix).
            if ($batch->run_id === null) {
                $this->materialService->issueForBatch($batch, $force);
            }

            $batch->update([
                'status' => 'in_progress',
                'started_at' => now(),
            ]);

            return $batch->fresh();
        });
    }

    /**
     * Complete batch production
     */
    public function completeBatch(ProductionBatch $batch, array $data): ProductionBatch
    {
        if ($batch->status !== 'in_progress') {
            throw new \Exception('Cannot complete batch not in production');
        }

        $this->guardStagesComplete($batch);

        $batch->update([
            'completed_quantity' => $data['completed_quantity'] ?? $batch->planned_quantity,
            'status' => 'qc_pending',
            'completed_at' => now(),
        ]);

        return $batch->fresh();
    }

    /**
     * Delete batch (draft only)
     */
    public function delete(ProductionBatch $batch): void
    {
        if ($batch->status !== 'draft') {
            throw new \Exception('Cannot delete batch not in draft status');
        }

        DB::transaction(function () use ($batch) {
            $batch->delete();

            // Release the order again if this was its last batch, mirroring the
            // claim in createFromOrder() (and ProductionRunService::cancel()).
            $order = $batch->order;
            if ($order && $order->status === 'in_production'
                && !$order->batches()->exists()) {
                $order->update(['status' => 'pending']);
            }
        });
    }

    /**
     * Stage gate (PB-031). Until now `areAllStagesCompleted()` existed but was
     * never called, so a batch could be completed — then QC'd and dispatched —
     * with zero stage logs. The stage system was decoration.
     *
     * Opt-in per company, and deliberately so: `areAllStagesCompleted()` returns
     * false when a company has no stages at all, so enforcing this globally
     * would make batches impossible to complete for any tenant not using stages,
     * and would strand every batch already in flight. A tenant switches
     * `enforce_production_stages` on once their stages are set up.
     *
     * @throws \Exception naming the stages still outstanding.
     */
    private function guardStagesComplete(ProductionBatch $batch): void
    {
        if (!($batch->company?->enforce_production_stages)) {
            return;
        }

        $stageLog = app(BatchStageLogService::class);
        if ($stageLog->areAllStagesCompleted($batch)) {
            return;
        }

        $outstanding = collect($stageLog->getProgressSummary($batch))
            ->reject(fn ($s) => $s['status'] === 'completed')
            ->map(fn ($s) => $s['stage_name'] . ' (' . $s['status'] . ')')
            ->implode(', ');

        throw new \Exception(
            'Cannot complete this batch until every production stage is completed and approved. Outstanding: '
            . ($outstanding ?: 'no stages have been started')
        );
    }

    /**
     * Calculate total quantity from order items
     */
    private function calculateTotalQuantity(Order $order): float
    {
        return $order->items->sum('quantity');
    }

    /**
     * Generate unique batch number
     */
    private function generateBatchNumber(int $companyId): string
    {
        $company = DB::table('companies')->find($companyId);
        $prefix = $company->batch_prefix ?? 'BATCH';

        $lastBatch = ProductionBatch::where('company_id', $companyId)
            ->orderByDesc('id')
            ->first();

        $sequence = $lastBatch ? $this->extractSequence($lastBatch->batch_no) + 1 : 1;

        $date = now()->format('Y');
        $sequenceNumber = str_pad($sequence, 6, '0', STR_PAD_LEFT);

        return "{$prefix}-{$date}-{$sequenceNumber}";
    }

    /**
     * Extract sequence number from batch number
     */
    private function extractSequence(string $batchNo): int
    {
        $parts = explode('-', $batchNo);
        return (int) end($parts);
    }
}
