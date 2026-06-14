<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductionBatch;
use App\Services\CuttingScheduleService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class CuttingScheduleController extends Controller
{
    use ApiResponse;

    private CuttingScheduleService $scheduleService;

    public function __construct(CuttingScheduleService $scheduleService)
    {
        $this->scheduleService = $scheduleService;
    }

    /**
     * POST /batches/{id}/calculate-cutting-schedule - Calculate optimal cutting schedule
     */
    public function calculateSchedule(Request $request, int $batchId)
    {
        try {
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)
                ->findOrFail($batchId);

            $schedule = $this->scheduleService->calculateSchedule($batch);

            // Validate schedule
            $validationErrors = $this->scheduleService->validateSchedule($schedule);
            if (!empty($validationErrors)) {
                return $this->errorResponse(
                    ['schedule' => $validationErrors],
                    'Schedule validation failed',
                    'SCHEDULE_VALIDATION_ERROR',
                    422
                );
            }

            return $this->successResponse(
                $schedule,
                'Cutting schedule calculated successfully'
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
                'Failed to calculate schedule',
                'SCHEDULE_ERROR',
                500
            );
        }
    }

    /**
     * GET /batches/{id}/cutting-schedule - Get cutting instructions
     */
    public function getInstructions(Request $request, int $batchId)
    {
        try {
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)
                ->findOrFail($batchId);

            $instructions = $this->scheduleService->getCuttingInstructions($batch);

            return response()->make($instructions, 200, [
                'Content-Type' => 'text/plain',
                'Content-Disposition' => "attachment; filename=\"cutting-schedule-{$batch->batch_no}.txt\"",
            ]);
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
                'Failed to retrieve instructions',
                'INSTRUCTIONS_ERROR',
                500
            );
        }
    }

    /**
     * GET /batches/{id}/cutting-schedule/json - Get schedule as JSON
     */
    public function getScheduleJson(Request $request, int $batchId)
    {
        try {
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)
                ->findOrFail($batchId);

            $schedule = $this->scheduleService->calculateSchedule($batch);
            $wastePercentage = $this->scheduleService->calculateWastePercentage($schedule);

            $schedule['waste_percentage'] = $wastePercentage;

            return $this->successResponse(
                $schedule,
                'Cutting schedule retrieved successfully'
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
                'Failed to retrieve schedule',
                'SCHEDULE_ERROR',
                500
            );
        }
    }
}
