<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BatchStageLog;
use App\Models\ProductionBatch;
use App\Models\ProductionStage;
use App\Services\BatchStageLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BatchStageLogController extends Controller
{
    use ApiResponse;

    private BatchStageLogService $stageLogService;

    public function __construct(BatchStageLogService $stageLogService)
    {
        $this->stageLogService = $stageLogService;
    }

    /**
     * GET /batches/{id}/timeline - Get batch stage timeline
     */
    public function getTimeline(Request $request, int $batchId)
    {
        try {
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)
                ->findOrFail($batchId);

            $timeline = $this->stageLogService->getBatchTimeline($batch);

            return $this->successResponse(
                $timeline,
                'Batch timeline retrieved successfully'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                ['id' => ['Batch not found']],
                'Batch not found',
                'NOT_FOUND',
                404
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to retrieve timeline',
                'TIMELINE_ERROR',
                500
            );
        }
    }

    /**
     * GET /batches/{id}/progress - Get batch stage progress
     */
    public function getProgress(Request $request, int $batchId)
    {
        try {
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)
                ->findOrFail($batchId);

            $progress = $this->stageLogService->getProgressSummary($batch);

            return $this->successResponse(
                $progress,
                'Batch progress retrieved successfully'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                ['id' => ['Batch not found']],
                'Batch not found',
                'NOT_FOUND',
                404
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to retrieve progress',
                'PROGRESS_ERROR',
                500
            );
        }
    }

    /**
     * POST /batches/{id}/stages/{stageId}/start - Start stage
     */
    public function startStage(Request $request, int $batchId, int $stageId)
    {
        try {
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)
                ->findOrFail($batchId);

            $stage = ProductionStage::where('company_id', $request->user()->company_id)
                ->findOrFail($stageId);

            $validated = $request->validate([
                'notes' => 'nullable|string|max:1000',
            ]);

            $log = $this->stageLogService->startStage(
                $batch,
                $stage,
                $request->user()->id,
                $validated['notes'] ?? null
            );

            return $this->createdResponse(
                $log->load('stage', 'user'),
                'Stage started successfully',
                201
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                ['id' => ['Batch or stage not found']],
                'Batch or stage not found',
                'NOT_FOUND',
                404
            );
        } catch (ValidationException $e) {
            return $this->errorResponse(
                $e->errors(),
                'Validation failed',
                'VALIDATION_ERROR',
                422
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                $e->getMessage(),
                'STAGE_START_ERROR',
                400
            );
        }
    }

    /**
     * POST /batches/{id}/stages/{stageId}/complete - Complete stage
     */
    public function completeStage(Request $request, int $batchId, int $stageId)
    {
        try {
            $log = BatchStageLog::where('batch_id', $batchId)
                ->where('stage_id', $stageId)
                ->firstOrFail();

            // Verify batch belongs to company
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)
                ->findOrFail($batchId);

            $validated = $request->validate([
                'notes' => 'nullable|string|max:1000',
            ]);

            $log = $this->stageLogService->completeStage(
                $log,
                $request->user()->id,
                $validated['notes'] ?? null
            );

            return $this->successResponse(
                $log->load('stage', 'user'),
                'Stage completed successfully'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                ['id' => ['Stage log not found']],
                'Stage log not found',
                'NOT_FOUND',
                404
            );
        } catch (ValidationException $e) {
            return $this->errorResponse(
                $e->errors(),
                'Validation failed',
                'VALIDATION_ERROR',
                422
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to complete stage',
                'STAGE_COMPLETE_ERROR',
                400
            );
        }
    }
}
