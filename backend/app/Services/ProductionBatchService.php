<?php

namespace App\Services;

use App\Models\ProductionBatch;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class ProductionBatchService
{
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
    public function startProduction(ProductionBatch $batch): ProductionBatch
    {
        if ($batch->status !== 'draft') {
            throw new \Exception('Cannot start production for batch not in draft status');
        }

        $batch->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        return $batch->fresh();
    }

    /**
     * Complete batch production
     */
    public function completeBatch(ProductionBatch $batch, array $data): ProductionBatch
    {
        if ($batch->status !== 'in_progress') {
            throw new \Exception('Cannot complete batch not in production');
        }

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

        $batch->delete();
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
