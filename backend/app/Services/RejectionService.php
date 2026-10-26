<?php

namespace App\Services;

use App\Models\ProductionBatch;
use App\Models\ProductionCutLine;
use App\Models\ProductionRejection;
use App\Models\WasteReasonCode;
use Illuminate\Support\Facades\DB;

/**
 * WR-2: rejected panels as records. A cut line's rejected_nos is always the sum
 * of its open rejections — it changes only by raising or voiding one.
 */
class RejectionService
{
    /** Panels can be found bad from the saw until they leave the factory. */
    private const OPEN_BATCH_STATUSES = ['in_progress', 'qc_pending', 'qc_failed', 'qc_passed', 'completed'];

    public function raise(ProductionBatch $batch, array $data, int $userId, ?int $qcId = null): ProductionRejection
    {
        if (!in_array($batch->status, self::OPEN_BATCH_STATUSES)) {
            throw new \Exception('Rejections can only be recorded on a batch that is in production or awaiting dispatch.');
        }
        if (!in_array($data['stage_found'], ProductionRejection::STAGES)) {
            throw new \Exception('Unknown stage: ' . $data['stage_found']);
        }

        $reason = WasteReasonCode::where('company_id', $batch->company_id)->find($data['reason_code_id']);
        if (!$reason || !$reason->is_active || !in_array($reason->category, ProductionRejection::REASON_CATEGORIES)) {
            throw new \Exception('Choose an active panel-rejection reason.');
        }

        return DB::transaction(function () use ($batch, $data, $userId, $qcId, $reason) {
            $line = ProductionCutLine::where('batch_id', $batch->id)->lockForUpdate()->findOrFail($data['cut_line_id']);
            $nos  = (int) $data['nos'];
            if ($nos < 1 || $nos > $line->good_nos) {
                throw new \Exception("Only {$line->good_nos} good panels of {$line->panel_length_mm} mm can be rejected.");
            }

            $rejection = ProductionRejection::create([
                'company_id'         => $batch->company_id,
                'batch_id'           => $batch->id,
                'cut_line_id'        => $line->id,
                'reason_code_id'     => $reason->id,
                'quality_control_id' => $qcId,
                'stage_found'        => $data['stage_found'],
                'nos'                => $nos,
                'panel_length_mm'    => $line->panel_length_mm,
                'width_mm'           => $line->width_mm,
                'sqm'                => round($line->panel_length_mm / 1000 * $line->width_mm / 1000 * $nos, 4),
                'notes'              => $data['notes'] ?? null,
                'status'             => 'open',
                'raised_by_user_id'  => $userId,
            ]);

            $line->increment('rejected_nos', $nos);
            $this->syncCompleted($batch);

            return $rejection;
        });
    }

    /** Correct a mistaken record: the panels count as good again. */
    public function void(ProductionRejection $rejection, int $userId, string $reason): ProductionRejection
    {
        return DB::transaction(function () use ($rejection, $userId, $reason) {
            $rejection = ProductionRejection::lockForUpdate()->findOrFail($rejection->id);
            if ($rejection->status !== 'open') {
                throw new \Exception('Only an open rejection can be voided.');
            }

            $rejection->update([
                'status' => 'void', 'voided_by_user_id' => $userId, 'voided_at' => now(), 'void_reason' => $reason,
            ]);
            if ($rejection->cut_line_id) {
                ProductionCutLine::lockForUpdate()->find($rejection->cut_line_id)?->decrement('rejected_nos', $rejection->nos);
            }
            $this->syncCompleted($rejection->batch);

            return $rejection->fresh();
        });
    }

    public function listForBatch(ProductionBatch $batch)
    {
        return ProductionRejection::where('batch_id', $batch->id)
            ->with('reason:id,code,label,category', 'raisedBy:id,name', 'voidedBy:id,name', 'attachments')
            ->latest('id')
            ->get();
    }

    /**
     * After production, a batch's output is its good panels: keep completed_quantity
     * in step when panels are rejected (or un-rejected) at QC or in the store.
     */
    private function syncCompleted(ProductionBatch $batch): void
    {
        if (in_array($batch->status, ['draft', 'in_progress'])) {
            return; // completeBatch() derives it when production ends
        }
        $lines = $batch->cutLines()->get();
        if ($lines->sum('cut_nos') > 0) {
            $batch->update(['completed_quantity' => round($lines->sum('good_sqm'), 2)]);
        }
    }
}
