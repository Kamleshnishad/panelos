<?php

namespace App\Services;

use App\Models\BatchStageLog;
use App\Models\ProductionBatch;
use App\Models\ProductionStage;
use Illuminate\Support\Facades\DB;

class BatchStageLogService
{
    /**
     * Start stage for batch
     */
    public function startStage(ProductionBatch $batch, ProductionStage $stage, int $userId, ?string $notes = null): BatchStageLog
    {
        return DB::transaction(function () use ($batch, $stage, $userId, $notes) {
            // Lock the batch so concurrent "Start" clicks serialize; the
            // already-started check must run after the lock is held.
            ProductionBatch::where('id', $batch->id)->lockForUpdate()->firstOrFail();
            $this->validateStageStartable($batch, $stage);

            $log = BatchStageLog::create([
                'batch_id' => $batch->id,
                'stage_id' => $stage->id,
                'status' => 'in_progress',
                'started_at' => now(),
                'notes' => $notes,
                'logged_by_user_id' => $userId,
            ]);

            return $log;
        });
    }

    /**
     * Complete stage for batch
     */
    public function completeStage(BatchStageLog $log, int $userId, ?string $notes = null): BatchStageLog
    {
        if ($log->status !== 'in_progress') {
            throw new \Exception('Can only complete stages that are in progress');
        }

        $completedAt = now();
        $duration = $completedAt->diffInMinutes($log->started_at);

        // A stage flagged requires_approval parks at pending_approval until a
        // supervisor signs it off; the next stage stays locked until then.
        $needsApproval = (bool) ($log->stage?->requires_approval);

        $log->update([
            'status' => $needsApproval ? 'pending_approval' : 'completed',
            'completed_at' => $completedAt,
            'duration_minutes' => $duration,
            'notes' => $notes ?? $log->notes,
            'logged_by_user_id' => $userId,
        ]);

        return $log->fresh();
    }

    /**
     * Sign off a completed stage. This is the gate the next stage waits on.
     *
     * @throws \Exception when the stage is not awaiting approval, or when the
     *                    company requires four-eyes and the approver ran the step.
     */
    public function approveStage(BatchStageLog $log, int $userId): BatchStageLog
    {
        if ($log->status !== 'pending_approval') {
            throw new \Exception('Only a stage awaiting approval can be approved.');
        }

        $company = $log->batch?->company;
        if (($company?->stage_approval_four_eyes) && (int) $log->logged_by_user_id === $userId) {
            throw new \Exception('This company requires a second person to approve a stage. You ran this step yourself.');
        }

        $log->update([
            'status'              => 'completed',
            'approved_by_user_id' => $userId,
            'approved_at'         => now(),
            'rejected_at'         => null,
            'rejection_reason'    => null,
        ]);

        return $log->fresh();
    }

    /**
     * Reject a completed stage and send it back to the floor. The log is reset
     * so the operator can start the same stage again (validateStageStartable
     * treats a rejected log as "not yet done").
     */
    public function rejectStage(BatchStageLog $log, int $userId, string $reason): BatchStageLog
    {
        if ($log->status !== 'pending_approval') {
            throw new \Exception('Only a stage awaiting approval can be rejected.');
        }

        $log->update([
            'status'              => 'rejected',
            'approved_by_user_id' => $userId,
            'approved_at'         => null,
            'rejected_at'         => now(),
            'rejection_reason'    => $reason,
            'completed_at'        => null,
        ]);

        return $log->fresh();
    }

    /**
     * Get batch stage timeline
     */
    public function getBatchTimeline(ProductionBatch $batch)
    {
        return BatchStageLog::where('batch_id', $batch->id)
            ->with('stage', 'user')
            ->orderBy('stage_id', 'asc')
            ->get();
    }

    /**
     * Get current stage for batch
     */
    public function getCurrentStage(ProductionBatch $batch): ?BatchStageLog
    {
        return BatchStageLog::where('batch_id', $batch->id)
            ->whereNull('completed_at')
            ->orderBy('started_at', 'desc')
            ->first();
    }

    /**
     * Get next stage to be completed
     */
    public function getNextStage(ProductionBatch $batch): ?ProductionStage
    {
        $completedStageIds = BatchStageLog::where('batch_id', $batch->id)
            ->whereNotNull('completed_at')
            ->pluck('stage_id')
            ->toArray();

        return ProductionStage::where('company_id', $batch->company_id)
            ->where('is_active', true)
            ->whereNotIn('id', $completedStageIds)
            ->orderBy('sequence', 'asc')
            ->first();
    }

    /**
     * Check if all stages completed
     */
    public function areAllStagesCompleted(ProductionBatch $batch): bool
    {
        $totalStages = ProductionStage::where('company_id', $batch->company_id)
            ->where('is_active', true)
            ->count();

        $completedStages = BatchStageLog::where('batch_id', $batch->id)
            ->where('status', 'completed')
            ->count();

        return $totalStages === $completedStages && $totalStages > 0;
    }

    /**
     * Validate stage can be started
     */
    private function validateStageStartable(ProductionBatch $batch, ProductionStage $stage): void
    {
        // Check if previous stages are completed
        $previousStages = ProductionStage::where('company_id', $batch->company_id)
            ->where('is_active', true)
            ->where('sequence', '<', $stage->sequence)
            ->get();

        foreach ($previousStages as $prevStage) {
            // 'completed' only — a stage sitting at pending_approval or rejected
            // must not unlock the next one.
            $completed = BatchStageLog::where('batch_id', $batch->id)
                ->where('stage_id', $prevStage->id)
                ->where('status', 'completed')
                ->exists();

            if (!$completed) {
                throw new \Exception("Cannot start stage '{$stage->name}' until '{$prevStage->name}' is completed");
            }
        }

        // Check if stage already started
        // A rejected stage is deliberately re-runnable — that is the rework path.
        $alreadyStarted = BatchStageLog::where('batch_id', $batch->id)
            ->where('stage_id', $stage->id)
            ->where('status', '!=', 'rejected')
            ->exists();

        if ($alreadyStarted) {
            throw new \Exception("Stage '{$stage->name}' has already been started for this batch");
        }
    }

    /**
     * Get stage progress summary for batch
     */
    public function getProgressSummary(ProductionBatch $batch): array
    {
        $allStages = ProductionStage::where('company_id', $batch->company_id)
            ->where('is_active', true)
            ->orderBy('sequence', 'asc')
            ->get();

        $summary = [];
        foreach ($allStages as $stage) {
            $log = BatchStageLog::where('batch_id', $batch->id)
                ->where('stage_id', $stage->id)
                ->first();

            $summary[] = [
                'stage_id'         => $stage->id,
                'stage_name'       => $stage->name,
                'status'           => $log?->status ?? 'pending',
                'started_at'       => $log?->started_at,
                'completed_at'     => $log?->completed_at,
                'duration_minutes' => $log?->duration_minutes,
                'notes'            => $log?->notes,
            ];
        }

        return $summary;
    }
}
