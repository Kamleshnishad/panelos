<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\EInvoiceService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class EInvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(private EInvoiceService $svc) {}

    private function invoice(Request $r, int $id): Invoice
    {
        return Invoice::where('company_id', $r->user()->company_id)->findOrFail($id);
    }

    /** Plan-gate: e-Invoice/e-Way is a Pro+ feature. Returns a response when blocked. */
    private function planGate(Request $r): ?\Illuminate\Http\JsonResponse
    {
        $company = $r->user()->company;
        if ($company && !$company->canUseEinvoice()) {
            return $this->errorResponse(
                ['plan' => $company->subscription_plan],
                'e-Invoice & e-Way Bill require the Pro plan. Please upgrade to use this feature.',
                'PLAN_FEATURE_LOCKED',
                422
            );
        }
        return null;
    }

    /** Current IRN + e-Way Bill status. */
    public function status(Request $r, int $id)
    {
        try {
            return $this->successResponse($this->svc->status($this->invoice($r, $id)), 'e-Invoice status');
        } catch (\Exception $e) {
            return $this->errorResponse([], $e->getMessage(), 'NOT_FOUND', 404);
        }
    }

    /** Attempt GSP IRN generation (stub until keys set). */
    public function generateIrn(Request $r, int $id)
    {
        if ($deny = $this->planGate($r)) return $deny;
        try {
            $inv = $this->invoice($r, $id);
            if ($inv->status === 'cancelled') {
                return $this->errorResponse([], 'Cannot generate IRN for a cancelled invoice.', 'INVALID_STATUS', 422);
            }
            $result = $this->svc->generateIrn($inv);
            return $result['success']
                ? $this->successResponse($result['data'] ?? $result, 'IRN generated')
                : $this->errorResponse([], $result['message'], 'GSP_DISABLED', 422);
        } catch (\Exception $e) {
            return $this->errorResponse([], $e->getMessage(), 'IRN_ERROR', 400);
        }
    }

    /** Manually set IRN + ACK from the GST portal. */
    public function setIrnManual(Request $r, int $id)
    {
        if ($deny = $this->planGate($r)) return $deny;
        try {
            $inv = $this->invoice($r, $id);
            $data = $r->validate([
                'irn'          => 'required|string|size:64',
                'irn_ack_no'   => 'nullable|string|max:30',
                'irn_ack_date' => 'nullable|date',
                'irn_qr'       => 'nullable|string|max:4096',
            ]);
            $result = $this->svc->setIrnManual($inv, $data);
            return $result['success']
                ? $this->successResponse($this->svc->status($inv->fresh()), 'IRN saved')
                : $this->errorResponse([], $result['message'], 'IRN_ERROR', 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse([], $e->getMessage(), 'IRN_ERROR', 400);
        }
    }

    /** Cancel IRN (local record; manual GSP cancel or stub). */
    public function cancelIrn(Request $r, int $id)
    {
        if ($deny = $this->planGate($r)) return $deny;
        try {
            $inv = $this->invoice($r, $id);
            $data = $r->validate(['reason' => 'required|string|max:255']);
            $result = $this->svc->cancelIrn($inv, $data['reason']);
            return $result['success']
                ? $this->successResponse($this->svc->status($inv->fresh()), $result['message'])
                : $this->errorResponse([], $result['message'], 'CANCEL_ERROR', 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse([], $e->getMessage(), 'CANCEL_ERROR', 400);
        }
    }

    /** Attempt GSP e-Way Bill generation (stub). */
    public function generateEwayBill(Request $r, int $id)
    {
        if ($deny = $this->planGate($r)) return $deny;
        try {
            $inv = $this->invoice($r, $id);
            $transport = $r->only(['eway_transporter_id', 'eway_vehicle_no', 'eway_transport_mode', 'eway_distance_km', 'eway_doc_no']);
            $result = $this->svc->generateEwayBill($inv, $transport);
            return $result['success']
                ? $this->successResponse($result['data'] ?? $result, 'e-Way Bill generated')
                : $this->errorResponse([], $result['message'], 'GSP_DISABLED', 422);
        } catch (\Exception $e) {
            return $this->errorResponse([], $e->getMessage(), 'EWAY_ERROR', 400);
        }
    }

    /** Manually set e-Way Bill number. */
    public function setEwayBillManual(Request $r, int $id)
    {
        if ($deny = $this->planGate($r)) return $deny;
        try {
            $inv = $this->invoice($r, $id);
            $data = $r->validate([
                'eway_bill_no'        => 'required|string|max:20',
                'eway_bill_expiry'    => 'nullable|date',
                'eway_transporter_id' => 'nullable|string|max:50',
                'eway_vehicle_no'     => 'nullable|string|max:20',
                'eway_transport_mode' => 'nullable|in:road,rail,air,ship',
                'eway_distance_km'    => 'nullable|integer|min:0',
                'eway_doc_no'         => 'nullable|string|max:30',
            ]);
            $result = $this->svc->setEwayBillManual($inv, $data);
            return $result['success']
                ? $this->successResponse($this->svc->status($inv->fresh()), 'e-Way Bill saved')
                : $this->errorResponse([], $result['message'], 'EWAY_ERROR', 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse([], $e->getMessage(), 'EWAY_ERROR', 400);
        }
    }

    /** Cancel e-Way Bill. */
    public function cancelEwayBill(Request $r, int $id)
    {
        if ($deny = $this->planGate($r)) return $deny;
        try {
            $inv = $this->invoice($r, $id);
            $data = $r->validate(['reason' => 'nullable|string|max:255']);
            $result = $this->svc->cancelEwayBill($inv, $data['reason'] ?? 'Cancelled');
            return $result['success']
                ? $this->successResponse($this->svc->status($inv->fresh()), $result['message'])
                : $this->errorResponse([], $result['message'], 'CANCEL_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse([], $e->getMessage(), 'CANCEL_ERROR', 400);
        }
    }
}
