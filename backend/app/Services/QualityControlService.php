<?php

namespace App\Services;

use App\Models\ProductionBatch;
use App\Models\QualityControl;
use Illuminate\Support\Facades\DB;

class QualityControlService
{
    /**
     * Create quality control entry
     */
    public function create(ProductionBatch $batch, array $data, int $userId): QualityControl
    {
        if ($batch->status !== 'qc_pending') {
            throw new \Exception('Batch must be in qc_pending status to enter QC');
        }

        return DB::transaction(function () use ($batch, $data, $userId) {
            $qc = QualityControl::create([
                'batch_id' => $batch->id,
                'company_id' => $batch->company_id,
                'status' => $data['status'] ?? 'pass',
                'inspected_by_user_id' => $userId,
                'inspected_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            // Update batch status based on QC result
            if ($qc->status === 'pass') {
                $batch->update(['status' => 'qc_passed']);
            } elseif ($qc->status === 'fail') {
                $batch->update(['status' => 'qc_failed']);
            }

            return $qc->load('inspectedByUser');
        });
    }

    /**
     * Get QC for batch
     */
    public function getForBatch(ProductionBatch $batch): ?QualityControl
    {
        return QualityControl::where('batch_id', $batch->id)
            ->with('inspectedByUser')
            ->first();
    }

    /**
     * Update QC entry (if pending approval)
     */
    public function update(QualityControl $qc, array $data, int $userId): QualityControl
    {
        if ($qc->status !== 'pending_approval') {
            throw new \Exception('Can only update QC entries in pending_approval status');
        }

        $qc->update([
            'status' => $data['status'] ?? $qc->status,
            'notes' => $data['notes'] ?? $qc->notes,
            'inspected_by_user_id' => $userId,
        ]);

        return $qc->fresh();
    }

    /**
     * Approve QC entry
     */
    public function approve(QualityControl $qc, int $userId, ?string $notes = null): QualityControl
    {
        // Approval is the green-light for dispatch — must not be granted on a
        // failed QC. Failed batches need rework / reject, not approval.
        if ($qc->status !== 'pass') {
            throw new \Exception('Can only approve QC entries with pass status. Failed QC requires rework.');
        }

        $batch = $qc->batch;

        $qc->update([
            'approved_by_user_id' => $userId,
            'approved_at' => now(),
            'notes' => $notes ?? $qc->notes,
        ]);

        $batch->update(['status' => 'qc_passed']);

        return $qc->fresh();
    }

    /**
     * List QC entries for company
     */
    public function list(int $companyId, array $filters = [])
    {
        $query = QualityControl::where('company_id', $companyId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['batch_id'])) {
            $query->where('batch_id', $filters['batch_id']);
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->with('batch', 'inspectedByUser');
    }

    /**
     * Get QC details
     */
    public function getDetails(QualityControl $qc): QualityControl
    {
        return $qc->load('batch', 'inspectedByUser', 'approvedByUser');
    }

    /**
     * Get QC statistics for company
     */
    public function getStatistics(int $companyId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = QualityControl::where('company_id', $companyId);

        if ($dateFrom) {
            $query->where('inspected_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('inspected_at', '<=', $dateTo);
        }

        $total = $query->count();
        $passed = $query->clone()->where('status', 'pass')->count();
        $failed = $query->clone()->where('status', 'fail')->count();

        $passRate = $total > 0 ? round(($passed / $total) * 100, 2) : 0;

        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'pass_rate' => $passRate,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }
}
