<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductionBatch;
use App\Services\ProductionBatchService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductionBatchController extends Controller
{
    use ApiResponse;

    private ProductionBatchService $batchService;

    public function __construct(ProductionBatchService $batchService)
    {
        $this->batchService = $batchService;
    }

    /**
     * GET /batches - List all production batches
     */
    public function index(Request $request)
    {
        try {
            $filters = $request->only(['status', 'order_id', 'search', 'sort_by', 'sort_order']);

            $batches = $this->batchService->list(
                $request->user()->company_id,
                $filters
            );

            $perPage = $request->query('per_page', 20);
            $page = $request->query('page', 1);

            $paginated = $batches->paginate($perPage, ['*'], 'page', $page);

            return $this->paginatedResponse(
                $paginated->items(),
                $paginated,
                'Batches retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to retrieve batches',
                'BATCH_LIST_ERROR',
                500
            );
        }
    }

    /**
     * POST /orders/{orderId}/batches - Create batch from order
     */
    public function createFromOrder(Request $request, int $orderId)
    {
        try {
            $order = Order::where('company_id', $request->user()->company_id)
                ->findOrFail($orderId);

            $validated = $request->validate([
                'planned_quantity' => 'nullable|numeric|min:0.1',
                'notes' => 'nullable|string|max:1000',
            ]);

            $batch = $this->batchService->createFromOrder($order, $validated);

            return $this->createdResponse(
                $batch,
                'Production batch created successfully',
                201
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                ['id' => ['Order not found']],
                'Order not found',
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
                'Failed to create batch',
                'BATCH_CREATE_ERROR',
                500
            );
        }
    }

    /**
     * GET /batches/{id} - Get batch details
     */
    public function show(Request $request, int $id)
    {
        try {
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            $details = $this->batchService->getDetails($batch);

            return $this->successResponse(
                $details,
                'Batch retrieved successfully'
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
                'Failed to retrieve batch',
                'BATCH_SHOW_ERROR',
                500
            );
        }
    }

    /**
     * PUT /batches/{id} - Update batch
     */
    public function update(Request $request, int $id)
    {
        try {
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            if ($batch->status !== 'draft') {
                return $this->errorResponse(
                    ['status' => ['Can only update batches in draft status']],
                    'Invalid batch status',
                    'INVALID_STATUS',
                    400
                );
            }

            $validated = $request->validate([
                'planned_quantity' => 'nullable|numeric|min:0.1',
                'notes' => 'nullable|string|max:1000',
            ]);

            $batch = $this->batchService->update($batch, $validated);

            return $this->successResponse(
                $batch,
                'Batch updated successfully'
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
                'Failed to update batch',
                'BATCH_UPDATE_ERROR',
                500
            );
        }
    }

    /**
     * POST /batches/{id}/start - Start production
     */
    public function startProduction(Request $request, int $id)
    {
        try {
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            $batch = $this->batchService->startProduction($batch);

            return $this->successResponse(
                $batch,
                'Production started successfully'
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
                $e->getMessage(),
                'BATCH_START_ERROR',
                400
            );
        }
    }

    /**
     * POST /batches/{id}/complete - Complete production
     */
    public function completeBatch(Request $request, int $id)
    {
        try {
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            $validated = $request->validate([
                'completed_quantity' => 'nullable|numeric|min:0.1',
            ]);

            $batch = $this->batchService->completeBatch($batch, $validated);

            return $this->successResponse(
                $batch,
                'Batch production completed'
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
                'Failed to complete batch',
                'BATCH_COMPLETE_ERROR',
                500
            );
        }
    }

    /**
     * DELETE /batches/{id} - Delete batch (draft only)
     */
    public function destroy(Request $request, int $id)
    {
        try {
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            $this->batchService->delete($batch);

            return $this->noContentResponse(
                'Batch deleted successfully'
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
                'Failed to delete batch',
                'BATCH_DELETE_ERROR',
                400
            );
        }
    }
}
