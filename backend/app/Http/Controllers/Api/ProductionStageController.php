<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductionStage;
use App\Services\ProductionStageService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductionStageController extends Controller
{
    use ApiResponse;

    private ProductionStageService $stageService;

    public function __construct(ProductionStageService $stageService)
    {
        $this->stageService = $stageService;
    }

    /**
     * GET /production-stages - List all production stages
     */
    public function index(Request $request)
    {
        try {
            $filters = $request->only(['is_active']);

            $stages = $this->stageService->list(
                $request->user()->company_id,
                $filters
            );

            $data = $stages->get();

            return $this->successResponse(
                $data,
                'Production stages retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to retrieve stages',
                'STAGE_LIST_ERROR',
                500
            );
        }
    }

    /**
     * POST /production-stages - Create new stage
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:100',
                'description' => 'nullable|string|max:500',
                'sequence' => 'nullable|integer|min:1',
                'is_active' => 'nullable|boolean',
            ]);

            $validated['company_id'] = $request->user()->company_id;

            $stage = $this->stageService->create($validated);

            return $this->createdResponse(
                $stage,
                'Production stage created successfully',
                201
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
                'Failed to create stage',
                'STAGE_CREATE_ERROR',
                500
            );
        }
    }

    /**
     * GET /production-stages/{id} - Get stage details
     */
    public function show(Request $request, int $id)
    {
        try {
            $stage = $this->stageService->getById($request->user()->company_id, $id);

            return $this->successResponse(
                $stage,
                'Stage retrieved successfully'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                ['id' => ['Stage not found']],
                'Stage not found',
                'NOT_FOUND',
                404
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to retrieve stage',
                'STAGE_SHOW_ERROR',
                500
            );
        }
    }

    /**
     * PUT /production-stages/{id} - Update stage
     */
    public function update(Request $request, int $id)
    {
        try {
            $stage = $this->stageService->getById($request->user()->company_id, $id);

            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:100',
                'description' => 'nullable|string|max:500',
                'sequence' => 'nullable|integer|min:1',
                'is_active' => 'nullable|boolean',
            ]);

            $stage = $this->stageService->update($stage, $validated);

            return $this->successResponse(
                $stage,
                'Stage updated successfully'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                ['id' => ['Stage not found']],
                'Stage not found',
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
                'Failed to update stage',
                'STAGE_UPDATE_ERROR',
                500
            );
        }
    }

    /**
     * DELETE /production-stages/{id} - Delete stage
     */
    public function destroy(Request $request, int $id)
    {
        try {
            $stage = $this->stageService->getById($request->user()->company_id, $id);

            $this->stageService->delete($stage);

            return $this->noContentResponse(
                'Stage deleted successfully'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                ['id' => ['Stage not found']],
                'Stage not found',
                'NOT_FOUND',
                404
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to delete stage',
                'STAGE_DELETE_ERROR',
                400
            );
        }
    }
}
