<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

/**
 * e-Invoice (IRN/QR) + e-Way Bill service.
 *
 * Two modes:
 *  • Manual  — admin pastes IRN / ack-no / QR string from the GST portal.
 *              Always works, no keys needed. This is the mode shipped now.
 *  • Live GSP — when EINVOICE_GSP_URL + _CLIENT_ID + _CLIENT_SECRET + _GSTIN
 *               are set in .env, all generate* methods hit the GSP API instead.
 *
 * To go live later:
 *  1. Set env vars for your GSP (Karvy, ClearTax, etc.)
 *  2. Implement the TODO blocks in generateIrn / cancelIrn / generateEwayBill.
 *  3. The manual entry endpoints remain available as fallback.
 */
class EInvoiceService
{
    private bool $gspEnabled;

    public function __construct()
    {
        $this->gspEnabled = (bool) config('services.einvoice.enabled', false);
    }

    public function isGspEnabled(): bool
    {
        return $this->gspEnabled;
    }

    // ── IRN ─────────────────────────────────────────────────────────────

    /**
     * Generate IRN via GSP API.
     * Stub: returns not-enabled response until env keys are configured.
     */
    public function generateIrn(Invoice $invoice): array
    {
        if (!$this->gspEnabled) {
            return $this->notEnabled('IRN generation');
        }

        // TODO (when GSP keys are set):
        // 1. Build the JSON payload per IRP schema (seller/buyer GSTIN, items, tax, totals)
        // 2. POST to config('services.einvoice.url').'/generate'
        // 3. Parse response: irn, AckNo, AckDt, QRCode (base64)
        // 4. Call saveIrn($invoice, ...) and return the result

        return $this->notEnabled('IRN generation (GSP URL not configured)');
    }

    /**
     * Manually set IRN + ACK details received from the GST portal.
     * Always available — no GSP keys needed.
     */
    public function setIrnManual(Invoice $invoice, array $data): array
    {
        try {
            if ($invoice->irn_status === 'cancelled') {
                return ['success' => false, 'message' => 'IRN is cancelled — cannot update.'];
            }

            $invoice->update([
                'irn'              => $data['irn'],
                'irn_ack_no'       => $data['irn_ack_no'] ?? null,
                'irn_ack_date'     => $data['irn_ack_date'] ?? null,
                'irn_qr'           => $data['irn_qr'] ?? null,
                'irn_status'       => 'generated',
                'irn_generated_at' => now(),
            ]);

            Log::info('IRN set manually', ['invoice_id' => $invoice->id, 'irn' => $data['irn']]);

            return ['success' => true, 'data' => $invoice->fresh()];
        } catch (\Throwable $e) {
            Log::error('setIrnManual failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Cancel IRN via GSP, or record a manual cancellation.
     */
    public function cancelIrn(Invoice $invoice, string $reason): array
    {
        if ($invoice->irn_status !== 'generated') {
            return ['success' => false, 'message' => 'No active IRN to cancel.'];
        }

        if ($this->gspEnabled) {
            // TODO: POST to GSP cancel endpoint; on success fall through to update.
            return $this->notEnabled('IRN cancellation via GSP');
        }

        // Manual cancel (record intent; physical cancel done on GST portal)
        try {
            $invoice->update([
                'irn_status'        => 'cancelled',
                'irn_cancel_reason' => $reason,
            ]);
            return ['success' => true, 'message' => 'IRN marked cancelled locally. Complete cancellation on the GST portal.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ── e-Way Bill ───────────────────────────────────────────────────────

    /**
     * Generate e-Way Bill via GSP API.
     */
    public function generateEwayBill(Invoice $invoice, array $transport): array
    {
        if (!$this->gspEnabled) {
            return $this->notEnabled('e-Way Bill generation');
        }

        // TODO (when GSP keys are set):
        // 1. Build payload (transporter ID, vehicle no, mode, distance, doc no, party details)
        // 2. POST to config('services.einvoice.eway_url').'/generate'
        // 3. Parse: EwbNo, EwbDt, EwbValidTill
        // 4. Call saveEwayBill($invoice, ...) and return

        return $this->notEnabled('e-Way Bill (GSP URL not configured)');
    }

    /**
     * Manually set e-Way Bill number + details.
     * Always available.
     */
    public function setEwayBillManual(Invoice $invoice, array $data): array
    {
        try {
            $invoice->update([
                'eway_bill_no'           => $data['eway_bill_no'],
                'eway_bill_generated_at' => now(),
                'eway_bill_expiry'       => $data['eway_bill_expiry'] ?? null,
                'eway_bill_status'       => 'active',
                'eway_transporter_id'    => $data['eway_transporter_id'] ?? null,
                'eway_vehicle_no'        => $data['eway_vehicle_no'] ?? null,
                'eway_transport_mode'    => $data['eway_transport_mode'] ?? 'road',
                'eway_distance_km'       => $data['eway_distance_km'] ?? null,
                'eway_doc_no'            => $data['eway_doc_no'] ?? null,
            ]);

            Log::info('e-Way Bill set manually', ['invoice_id' => $invoice->id, 'eway_bill_no' => $data['eway_bill_no']]);

            return ['success' => true, 'data' => $invoice->fresh()];
        } catch (\Throwable $e) {
            Log::error('setEwayBillManual failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function cancelEwayBill(Invoice $invoice, string $reason): array
    {
        if ($invoice->eway_bill_status !== 'active') {
            return ['success' => false, 'message' => 'No active e-Way Bill to cancel.'];
        }

        if ($this->gspEnabled) {
            return $this->notEnabled('e-Way Bill cancellation via GSP');
        }

        try {
            $invoice->update(['eway_bill_status' => 'cancelled']);
            return ['success' => true, 'message' => 'e-Way Bill marked cancelled locally. Complete on the GST portal.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ── Status ───────────────────────────────────────────────────────────

    /** Current IRN + e-Way Bill status for an invoice. */
    public function status(Invoice $invoice): array
    {
        return [
            'gsp_enabled'          => $this->gspEnabled,
            'irn'                  => $invoice->irn,
            'irn_ack_no'           => $invoice->irn_ack_no,
            'irn_ack_date'         => $invoice->irn_ack_date,
            'irn_status'           => $invoice->irn_status ?? 'none',
            'irn_generated_at'     => $invoice->irn_generated_at,
            'irn_cancel_reason'    => $invoice->irn_cancel_reason,
            'has_qr'               => (bool) $invoice->irn_qr,
            'eway_bill_no'         => $invoice->eway_bill_no,
            'eway_bill_status'     => $invoice->eway_bill_status ?? 'none',
            'eway_bill_expiry'     => $invoice->eway_bill_expiry,
            'eway_transporter_id'  => $invoice->eway_transporter_id,
            'eway_vehicle_no'      => $invoice->eway_vehicle_no,
            'eway_transport_mode'  => $invoice->eway_transport_mode,
        ];
    }

    private function notEnabled(string $feature): array
    {
        return [
            'success' => false,
            'message' => "{$feature} requires GSP credentials. Set EINVOICE_GSP_ENABLED=true and related env vars, or use the manual entry form.",
            'gsp_enabled' => false,
        ];
    }
}
