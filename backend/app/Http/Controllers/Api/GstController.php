<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GstService;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GstController extends Controller
{
    protected $gstService;

    public function __construct(GstService $gstService)
    {
        $this->gstService = $gstService;
    }

    public function registerConfiguration(Request $request)
    {
        $validated = $request->validate([
            'state_code' => 'required|string|size:2',
            'gstin' => 'required|string|size:15|unique:gst_configurations,gstin',
            'registration_type' => 'required|in:regular,composition,exempted',
        ]);

        $companyId = auth()->user()->company_id;

        $result = $this->gstService->registerGstConfiguration(
            $companyId,
            $validated['state_code'],
            $validated['gstin'],
            $validated['registration_type']
        );

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => 'GST configuration registered successfully',
                'data' => $result['data']
            ], 201);
        }

        return response()->json($result, 400);
    }

    public function addHsnCode(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', Rule::unique('hsn_codes', 'code')->where('company_id', auth()->user()->company_id)],
            'description' => 'required|string',
            'category' => 'required|string',
            'gst_rate' => 'required|numeric|in:0,5,12,18,28',
            'cess_rate' => 'nullable|numeric|min:0',
        ]);

        $companyId = auth()->user()->company_id;

        $result = $this->gstService->addHsnCode(
            $companyId,
            $validated['code'],
            $validated['description'],
            $validated['category'],
            $validated['gst_rate'],
            $validated['cess_rate'] ?? 0
        );

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => 'HSN code added successfully',
                'data' => $result['data']
            ], 201);
        }

        return response()->json($result, 400);
    }

    public function calculateGst($invoiceId, Request $request)
    {
        $companyId = auth()->user()->company_id;

        try {
            $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);

            $gstRate = $request->get('gst_rate');
            $result = $this->gstService->calculateGst($invoice, $companyId, $gstRate);

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getGstBreakdown($invoiceId)
    {
        try {
            $result = $this->gstService->getGstBreakdown($invoiceId, auth()->user()->company_id);

            return response()->json($result, $result['success'] ? 200 : 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getConfigurations()
    {
        $companyId = auth()->user()->company_id;

        $result = $this->gstService->getCompanyGstConfigurations($companyId);

        return response()->json($result);
    }

    public function generateReport(Request $request)
    {
        $companyId = auth()->user()->company_id;

        $result = $this->gstService->generateGstReport(
            $companyId,
            $request->get('start_date'),
            $request->get('end_date')
        );

        return response()->json($result);
    }

    public function getCompliance()
    {
        $companyId = auth()->user()->company_id;

        $result = $this->gstService->getGstCompliance($companyId);

        return response()->json($result);
    }

    public function validateGstin(Request $request)
    {
        $validated = $request->validate([
            'gstin' => 'required|string',
            'state_code' => 'nullable|string|size:2',
        ]);

        $result = $this->gstService->validateGstin(
            $validated['gstin'],
            $validated['state_code'] ?? null
        );

        return response()->json([
            'success' => $result['valid'],
            'message' => $result['message']
        ], $result['valid'] ? 200 : 400);
    }

    public function getStatesList()
    {
        return response()->json([
            'success' => true,
            'data' => $this->gstService->getStatesList()
        ]);
    }
}
