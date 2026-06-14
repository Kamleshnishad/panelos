<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProductionBatch;
use App\Models\ProductionRun;
use Illuminate\Support\Facades\DB;

/**
 * Creates and drives a Production Run — a group of same-spec per-order batches
 * that the line runs together. Reuses ProductionBatchService for each child
 * batch so dispatch / QC / invoice (all per-order, per-batch) stay unchanged.
 */
class ProductionRunService
{
    public function __construct(private ProductionBatchService $batchService) {}

    /**
     * Build a run from a set of pending orders (the planner's group).
     * One child batch per order; orders move to in_production.
     */
    public function createFromOrders(int $companyId, array $orderIds, ?string $signature, ?string $label, ?string $notes): ProductionRun
    {
        return DB::transaction(function () use ($companyId, $orderIds, $signature, $label, $notes) {
            $orders = Order::where('company_id', $companyId)
                ->whereIn('id', $orderIds)
                ->where('status', 'pending')          // only un-started orders
                ->with('items')
                ->get();

            if ($orders->isEmpty()) {
                throw new \Exception('No eligible pending orders to start. They may already be in production.');
            }

            $run = ProductionRun::create([
                'company_id'  => $companyId,
                'run_no'      => $this->generateRunNumber($companyId),
                'status'      => 'draft',
                'signature'   => $signature,
                'label'       => $label,
                'planned_sqm' => 0,
                'notes'       => $notes,
            ]);

            $plannedSqm = 0;
            foreach ($orders as $order) {
                $batch = $this->batchService->createFromOrder($order, []);
                $batch->update(['run_id' => $run->id]);

                $order->update(['status' => 'in_production']);
                $plannedSqm += (float) ($order->total_sqm ?: $order->items->sum('total_sqm'));
            }

            $run->update(['planned_sqm' => $plannedSqm]);

            return $run->load('batches.order.customer');
        });
    }

    /** Start the whole run: every draft child batch goes in_progress. */
    public function start(ProductionRun $run): ProductionRun
    {
        if ($run->status !== 'draft') {
            throw new \Exception('Only a draft run can be started.');
        }
        return DB::transaction(function () use ($run) {
            foreach ($run->batches as $batch) {
                if ($batch->status === 'draft') {
                    $this->batchService->startProduction($batch);
                }
            }
            $run->update(['status' => 'in_progress', 'started_at' => now()]);
            return $run->fresh('batches.order.customer');
        });
    }

    /** Complete the run: every in_progress child batch goes to qc_pending. */
    public function complete(ProductionRun $run): ProductionRun
    {
        if ($run->status !== 'in_progress') {
            throw new \Exception('Only a run in progress can be completed.');
        }
        return DB::transaction(function () use ($run) {
            foreach ($run->batches as $batch) {
                if ($batch->status === 'in_progress') {
                    $this->batchService->completeBatch($batch, []);
                }
            }
            $run->update(['status' => 'completed', 'completed_at' => now()]);
            return $run->fresh('batches.order.customer');
        });
    }

    /** Cancel a draft run: drop child draft batches, return orders to pending. */
    public function cancel(ProductionRun $run): void
    {
        if ($run->status !== 'draft') {
            throw new \Exception('Only a draft run can be cancelled.');
        }
        DB::transaction(function () use ($run) {
            foreach ($run->batches as $batch) {
                if ($batch->status === 'draft') {
                    $batch->order?->update(['status' => 'pending']);
                    $batch->delete();
                }
            }
            $run->delete();
        });
    }

    public function list(int $companyId, array $filters = [])
    {
        $query = ProductionRun::where('company_id', $companyId)
            ->with('batches.order.customer');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('created_at');
    }

    public function getDetails(ProductionRun $run): ProductionRun
    {
        return $run->load('batches.order.customer', 'batches.orderItems');
    }

    private function generateRunNumber(int $companyId): string
    {
        $year = now()->format('Y');
        $last = ProductionRun::withTrashed()
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->value('run_no');

        $seq = 1;
        if ($last) {
            $parts = explode('-', $last);
            $seq = ((int) end($parts)) + 1;
        }

        return sprintf('RUN-%s-%04d', $year, $seq);
    }
}
