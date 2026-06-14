<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispatch;
use App\Models\ProductionBatch;
use App\Services\DispatchService;
use App\Services\DispatchPdfService;
use Illuminate\Http\Request;

class DispatchController extends Controller
{
    protected $dispatchService;
    protected $pdfService;

    public function __construct(DispatchService $dispatchService, DispatchPdfService $pdfService)
    {
        $this->dispatchService = $dispatchService;
        $this->pdfService = $pdfService;
    }

    public function index(Request $request)
    {
        try {
            $filters = $request->only(['status', 'search', 'from_date', 'to_date']);
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 20);

            $companyId = auth()->user()->company_id;

            $dispatches = $this->dispatchService->listDispatches($filters, $companyId, $page, $perPage);

            return $this->apiResponse(true, $dispatches, 'Dispatches retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 500);
        }
    }

    public function store(Request $request, $batchId)
    {
        try {
            $request->validate([
                'customer_address' => 'string',
                'expected_delivery_date' => 'nullable|date',
                'tracking_number' => 'nullable|string',
                'auto_allocate' => 'boolean',
                'notes' => 'nullable|string'
            ]);

            $companyId = auth()->user()->company_id;

            $dispatch = $this->dispatchService->createDispatch($batchId, $request->all(), $companyId);

            if ($request->input('auto_allocate', false)) {
                $this->dispatchService->allocateStockForDispatch($dispatch->id, $companyId);
                $dispatch->refresh();
            }

            return $this->apiResponse(true, $dispatch, 'Dispatch created successfully', 201);
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function show($id)
    {
        try {
            $companyId = auth()->user()->company_id;
            $dispatch = $this->dispatchService->getDispatchDetails($id, $companyId);

            return $this->apiResponse(true, $dispatch, 'Dispatch retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'customer_address' => 'string',
                'expected_delivery_date' => 'nullable|date',
                'tracking_number' => 'nullable|string',
                'notes' => 'nullable|string'
            ]);

            $companyId = auth()->user()->company_id;

            $dispatch = $this->dispatchService->updateDispatch($id, $request->all(), $companyId);

            return $this->apiResponse(true, $dispatch, 'Dispatch updated successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function destroy($id)
    {
        try {
            $companyId = auth()->user()->company_id;

            $dispatch = Dispatch::where('company_id', $companyId)->findOrFail($id);
            $this->dispatchService->cancelDispatch($id, $companyId);

            return $this->apiResponse(true, null, 'Dispatch cancelled successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function allocate(Request $request, $id)
    {
        try {
            $companyId = auth()->user()->company_id;

            $allocations = $this->dispatchService->allocateStockForDispatch($id, $companyId);

            return $this->apiResponse(true, [
                'dispatch_id' => $id,
                'allocations' => $allocations,
                'all_items_allocated' => count($allocations) > 0
            ], 'Stock allocated successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function complete(Request $request, $id)
    {
        try {
            $request->validate([
                'actual_delivery_date' => 'nullable|date'
            ]);

            $companyId = auth()->user()->company_id;

            $dispatch = $this->dispatchService->completeDispatch($id, $request->all(), $companyId);

            return $this->apiResponse(true, $dispatch, 'Dispatch completed successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function getChallan($id)
    {
        try {
            $companyId = auth()->user()->company_id;

            $challanData = $this->dispatchService->generateChallan($id, $companyId);

            return $this->apiResponse(true, $challanData, 'Challan data retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function getChallanPdf(Request $request, $id)
    {
        try {
            $companyId = auth()->user()->company_id;

            $dispatch = Dispatch::where('company_id', $companyId)->findOrFail($id);

            // ?download=1 forces attachment, otherwise inline stream
            return $request->boolean('download')
                ? $this->pdfService->download($dispatch)
                : $this->pdfService->stream($dispatch);
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function getDispatchesByBatch($batchId)
    {
        try {
            $companyId = auth()->user()->company_id;

            $dispatches = Dispatch::where('company_id', $companyId)
                ->where('batch_id', $batchId)
                ->with('items', 'allocations')
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->apiResponse(true, $dispatches, 'Batch dispatches retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }
}
