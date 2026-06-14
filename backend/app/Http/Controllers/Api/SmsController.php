<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SmsService;
use App\Models\Invoice;
use Illuminate\Http\Request;

class SmsController extends Controller
{
    protected $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    public function sendPaymentReminder($invoiceId)
    {
        $companyId = auth()->user()->company_id;

        try {
            $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);

            $result = $this->smsService->sendPaymentReminder($invoice, $companyId);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment reminder SMS sent successfully',
                    'data' => $result
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message']
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function sendCustomSms(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => 'required|string',
            'message' => 'required|string|max:160',
        ]);

        $companyId = auth()->user()->company_id;

        try {
            $validation = $this->smsService->validatePhoneNumber($validated['phone_number']);

            if (!$validation['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $validation['message']
                ], 400);
            }

            $result = $this->smsService->sendCustomMessage(
                $validated['phone_number'],
                $validated['message'],
                $companyId
            );

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'SMS sent successfully',
                    'data' => $result
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message']
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function validatePhoneNumber(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => 'required|string'
        ]);

        $result = $this->smsService->validatePhoneNumber($validated['phone_number']);

        return response()->json([
            'success' => $result['valid'],
            'message' => $result['message'] ?? 'Valid phone number',
            'normalized' => $result['normalized'] ?? null
        ], $result['valid'] ? 200 : 400);
    }

    public function getSmsLogs()
    {
        $companyId = auth()->user()->company_id;
        $limit = request()->get('limit', 50);

        try {
            $logs = $this->smsService->getSmsLogs($companyId, $limit);

            return response()->json([
                'success' => true,
                'data' => $logs
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getSmsStatus()
    {
        return response()->json([
            'success' => true,
            'enabled' => $this->smsService->isEnabled(),
            'provider' => 'Twilio'
        ]);
    }
}
