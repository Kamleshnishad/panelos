<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductionRun;
use App\Services\ProductionRunService;
use App\Services\MaterialBomService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductionRunController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ProductionRunService $runService,
        private MaterialBomService $bomService,
    ) {}

    /** Advisory raw-material requirement for a run + stock availability. */
    public function materialRequirement(Request $request, int $id)
    {
        try {
            $run = ProductionRun::where('company_id', $request->user()->company_id)->findOrFail($id);
            return $this->successResponse($this->bomService->requirementForRun($run), 'Material requirement computed');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'BOM_ERROR', 400);
        }
    }

    public function index(Request $request)
    {
        try {
            $filters = $request->only(['status']);
            $runs = $this->runService->list($request->user()->company_id, $filters)->get();
            return $this->successResponse($runs, 'Production runs retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'Failed to list runs', 'LIST_ERROR', 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'order_ids'   => 'required|array|min:1',
                'order_ids.*' => 'integer',
                'signature'   => 'nullable|string|max:255',
                'label'       => 'nullable|string|max:255',
                'notes'       => 'nullable|string|max:1000',
            ]);

            $run = $this->runService->createFromOrders(
                $request->user()->company_id,
                $data['order_ids'],
                $data['signature'] ?? null,
                $data['label'] ?? null,
                $data['notes'] ?? null,
            );

            return $this->createdResponse($run, 'Production run created', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'CREATE_ERROR', 400);
        }
    }

    public function show(Request $request, int $id)
    {
        try {
            $run = ProductionRun::where('company_id', $request->user()->company_id)->findOrFail($id);
            return $this->successResponse($this->runService->getDetails($run), 'Run retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse([], 'Run not found', 'NOT_FOUND', 404);
        }
    }

    public function start(Request $request, int $id)
    {
        return $this->action($request, $id, fn($run) => $this->runService->start($run), 'Run started');
    }

    public function complete(Request $request, int $id)
    {
        return $this->action($request, $id, fn($run) => $this->runService->complete($run), 'Run completed');
    }

    public function destroy(Request $request, int $id)
    {
        try {
            $run = ProductionRun::where('company_id', $request->user()->company_id)->findOrFail($id);
            $this->runService->cancel($run);
            return $this->noContentResponse('Run cancelled');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'CANCEL_ERROR', 400);
        }
    }

    private function action(Request $request, int $id, callable $fn, string $msg)
    {
        try {
            $run = ProductionRun::where('company_id', $request->user()->company_id)->findOrFail($id);
            return $this->successResponse($fn($run), $msg);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'RUN_ACTION_ERROR', 400);
        }
    }
}
