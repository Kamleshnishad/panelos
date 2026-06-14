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
        // Validate stage can be started
        $this->validateStageStartable($batch, $stage);

        return DB::transaction(function () use ($batch, $stage, $userId, $notes) {
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

        $log->update([
            'status' => 'completed',
            'completed_at' => $completedAt,
            'duration_minutes' => $duration,
            'notes' => $notes ?? $log->notes,
            'logged_by_user_id' => $userId,
        ]);

        return $log->fresh();
    }

    /**
     * Get batch stage timeline
     */
    public function getBatchTimeline(ProductionBatch $batch)
    {
        return BatchStageLog::where('batch_id', $batch->id)
            ->with('stage', 'loggedByUser')
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
            ->whereNotNull('completed_at')
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
            $completed = BatchStageLog::where('batch_id', $batch->id)
                ->where('stage_id', $prevStage->id)
                ->whereNotNull('completed_at')
                ->exists();

            if (!$completed) {
                throw new \Exception("Cannot start stage '{$stage->name}' until '{$prevStage->name}' is completed");
            }
        }

        // Check if stage already started
        $alreadyStarted = BatchStageLog::where('batch_id', $batch->id)
            ->where('stage_id', $stage->id)
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
