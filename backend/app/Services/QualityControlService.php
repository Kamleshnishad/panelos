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
        // qc_failed batches may be re-inspected (e.g. after the failed panels were
        // dealt with); every inspection is kept as history, latest one wins (WR-0).
        if (!in_array($batch->status, ['qc_pending', 'qc_failed'])) {
            throw new \Exception('Batch must be in qc_pending or qc_failed status to enter QC');
        }

        return DB::transaction(function () use ($batch, $data, $userId) {
            $qc = QualityControl::create([
                'batch_id' => $batch->id,
                'company_id' => $batch->company_id,
                'status' => $data['status'] ?? 'pass',
                'inspected_by_user_id' => $userId,
                'inspected_at' => now(),
                'notes' => $data['notes'] ?? null,
                'checklist' => $data['checklist'] ?? null,
                'measurements' => $data['measurements'] ?? null,
            ]);

            // Panels found bad at this inspection become rejection records (WR-2),
            // raised before the batch moves on so the open-batch rule still holds.
            foreach ($data['rejections'] ?? [] as $r) {
                app(RejectionService::class)->raise($batch, $r + ['stage_found' => 'qc'], $userId, $qc->id);
            }

            // Update batch status based on QC result
            // rework_required sends the batch back to the floor; completing it
            // again returns it to qc_pending for a fresh inspection.
            $batch->update(['status' => match ($qc->status) {
                'pass'            => 'qc_passed',
                'fail'            => 'qc_failed',
                'rework_required' => 'in_progress',
            }]);

            return $qc->load('inspectedByUser');
        });
    }

    /**
     * Get QC for batch
     */
    public function getForBatch(ProductionBatch $batch): ?QualityControl
    {
        return QualityControl::where('batch_id', $batch->id)
            ->with('inspectedByUser', 'approvedByUser')
            ->latest('id')
            ->first();
    }

    /** Every inspection of a batch, newest first. */
    public function historyForBatch(ProductionBatch $batch)
    {
        return QualityControl::where('batch_id', $batch->id)
            ->with('inspectedByUser', 'approvedByUser')
            ->latest('id')
            ->get();
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

        \App\Support\SafeSort::apply($query, $filters, 'created_at', 'desc');

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
