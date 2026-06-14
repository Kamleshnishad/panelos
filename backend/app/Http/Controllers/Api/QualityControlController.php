<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductionBatch;
use App\Models\QualityControl;
use App\Services\QualityControlService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class QualityControlController extends Controller
{
    use ApiResponse;

    private QualityControlService $qcService;

    public function __construct(QualityControlService $qcService)
    {
        $this->qcService = $qcService;
    }

    /**
     * GET /quality-control - List QC entries
     */
    public function index(Request $request)
    {
        try {
            $filters = $request->only(['status', 'batch_id', 'sort_by', 'sort_order']);

            $qcEntries = $this->qcService->list(
                $request->user()->company_id,
                $filters
            );

            $perPage = $request->query('per_page', 20);
            $page = $request->query('page', 1);

            $paginated = $qcEntries->paginate($perPage, ['*'], 'page', $page);

            return $this->paginatedResponse(
                $paginated->items(),
                $paginated,
                'QC entries retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to retrieve QC entries',
                'QC_LIST_ERROR',
                500
            );
        }
    }

    /**
     * POST /batches/{id}/qc - Create QC entry
     */
    public function createForBatch(Request $request, int $batchId)
    {
        try {
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)
                ->findOrFail($batchId);

            $validated = $request->validate([
                'status' => 'required|in:pass,fail',
                'notes' => 'nullable|string|max:1000',
            ]);

            $qc = $this->qcService->create($batch, $validated, $request->user()->id);

            return $this->createdResponse(
                $qc,
                'Quality control entry created successfully',
                201
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                ['id' => ['Batch not found']],
                'Batch not found',
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
                'Failed to create QC entry',
                'QC_CREATE_ERROR',
                400
            );
        }
    }

    /**
     * GET /batches/{id}/qc - Get QC for batch
     */
    public function getForBatch(Request $request, int $batchId)
    {
        try {
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)
                ->findOrFail($batchId);

            $qc = $this->qcService->getForBatch($batch);

            if (!$qc) {
                return $this->errorResponse(
                    ['batch_id' => ['No QC entry found for this batch']],
                    'QC entry not found',
                    'NOT_FOUND',
                    404
                );
            }

            return $this->successResponse(
                $this->qcService->getDetails($qc),
                'QC entry retrieved successfully'
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
                'Failed to retrieve QC entry',
                'QC_GET_ERROR',
                500
            );
        }
    }

    /**
     * GET /quality-control/{id} - Get QC details
     */
    public function show(Request $request, int $id)
    {
        try {
            $qc = QualityControl::where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            $details = $this->qcService->getDetails($qc);

            return $this->successResponse(
                $details,
                'QC entry retrieved successfully'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                ['id' => ['QC entry not found']],
                'QC entry not found',
                'NOT_FOUND',
                404
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to retrieve QC entry',
                'QC_SHOW_ERROR',
                500
            );
        }
    }

    /**
     * POST /quality-control/{id}/approve - Approve QC entry
     */
    public function approve(Request $request, int $id)
    {
        try {
            $qc = QualityControl::where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            $validated = $request->validate([
                'notes' => 'nullable|string|max:1000',
            ]);

            $qc = $this->qcService->approve($qc, $request->user()->id, $validated['notes'] ?? null);

            return $this->successResponse(
                $this->qcService->getDetails($qc),
                'QC entry approved successfully'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                ['id' => ['QC entry not found']],
                'QC entry not found',
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
                'Failed to approve QC entry',
                'QC_APPROVE_ERROR',
                400
            );
        }
    }

    /**
     * GET /quality-control/statistics - Get QC statistics
     */
    public function statistics(Request $request)
    {
        try {
            $dateFrom = $request->query('date_from');
            $dateTo = $request->query('date_to');

            $stats = $this->qcService->getStatistics(
                $request->user()->company_id,
                $dateFrom,
                $dateTo
            );

            return $this->successResponse(
                $stats,
                'QC statistics retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to retrieve statistics',
                'STATISTICS_ERROR',
                500
            );
        }
    }
}
