<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductionRun;
use App\Services\ProductionRunService;
use App\Services\MaterialBomService;
use App\Services\ProductionMaterialService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductionRunController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ProductionRunService $runService,
        private MaterialBomService $bomService,
        private ProductionMaterialService $materialService,
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

    /** Draft-PO suggestion covering a run's material shortage. */
    public function poSuggestion(Request $request, int $id)
    {
        try {
            $run = ProductionRun::where('company_id', $request->user()->company_id)->findOrFail($id);
            return $this->successResponse($this->materialService->poSuggestionForRun($run), 'PO suggestion computed');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'PO_SUGGEST_ERROR', 400);
        }
    }

    public function index(Request $request)
    {
        try {
            $filters = $request->only(['status']);
            $page    = (int) $request->input('page', 1);
            $perPage = (int) $request->input('per_page', 30);
            $paginated = $this->runService->list($request->user()->company_id, $filters)
                ->paginate($perPage, ['*'], 'page', $page);
            return response()->json([
                'success' => true,
                'data'    => $paginated->items(),
                'meta'    => ['pagination' => [
                    'total'        => $paginated->total(),
                    'current_page' => $paginated->currentPage(),
                    'per_page'     => $paginated->perPage(),
                    'last_page'    => $paginated->lastPage(),
                ]],
                'message' => 'Production runs retrieved',
            ]);
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
        try {
            $run = ProductionRun::where('company_id', $request->user()->company_id)->findOrFail($id);
            $result = $this->runService->start($run, $request->boolean('force'));
            return $this->successResponse($result, 'Run started');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'RUN_START_ERROR', 400);
        }
    }

    public function complete(Request $request, int $id)
    {
        try {
            $run = ProductionRun::where('company_id', $request->user()->company_id)->findOrFail($id);
            $actuals = $request->input('actuals', []);
            return $this->successResponse($this->runService->complete($run, is_array($actuals) ? $actuals : []), 'Run completed');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'RUN_COMPLETE_ERROR', 400);
        }
    }

    /** Material usage rows for a run (issued + actual) — for the complete form. */
    public function materialUsage(Request $request, int $id)
    {
        try {
            $run = ProductionRun::where('company_id', $request->user()->company_id)->findOrFail($id);
            return $this->successResponse($this->materialService->usageForRun($run), 'Material usage retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'USAGE_ERROR', 400);
        }
    }

    /** Wastage summary (actual vs standard) across runs in a date range. */
    public function wastageReport(Request $request)
    {
        try {
            $data = $this->materialService->wastageReport(
                $request->user()->company_id,
                $request->query('from'),
                $request->query('to'),
            );
            return $this->successResponse($data, 'Wastage report generated');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'WASTAGE_ERROR', 400);
        }
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
