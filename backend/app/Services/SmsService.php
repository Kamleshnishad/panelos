<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\SmsLog;
use Twilio\Rest\Client;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected $client;
    protected $twilioPhone;
    protected $enabled;
    protected $whatsappEnabled;
    protected $whatsappFrom;

    public function __construct()
    {
        $this->enabled = config('services.twilio.enabled', false);
        $this->whatsappEnabled = config('services.twilio.whatsapp_enabled', false);
        $this->whatsappFrom = config('services.twilio.whatsapp_from');

        if ($this->enabled || $this->whatsappEnabled) {
            $accountSid = config('services.twilio.account_sid');
            $authToken = config('services.twilio.auth_token');
            $this->twilioPhone = config('services.twilio.from_number');

            $this->client = new Client($accountSid, $authToken);
        }
    }

    public function isWhatsappEnabled(): bool
    {
        return (bool) $this->whatsappEnabled;
    }

    /**
     * Send a payment reminder over WhatsApp (Twilio whatsapp: channel).
     * Pluggable — no-op with a clear message until TWILIO_WHATSAPP_ENABLED=true
     * and a whatsapp_from number are configured.
     */
    public function sendPaymentReminderWhatsApp(Invoice $invoice, $companyId, $phoneNumber = null)
    {
        if (!$this->whatsappEnabled) {
            return ['success' => false, 'message' => 'WhatsApp service not enabled'];
        }

        try {
            $phone = $phoneNumber ?? $this->getCustomerPhone($invoice);
            if (!$phone) {
                return ['success' => false, 'message' => 'No WhatsApp number available'];
            }

            $remainingDue = $this->calculateRemainingDue($invoice->id);
            $daysOverdue  = max(0, now()->diffInDays($invoice->due_date));
            $message      = $this->formatPaymentReminderMessage($invoice, $remainingDue, $daysOverdue);

            $result = $this->client->messages->create(
                'whatsapp:' . $phone,
                ['from' => 'whatsapp:' . $this->whatsappFrom, 'body' => $message]
            );

            $this->logSms($companyId, $invoice->id, 'payment_reminder_whatsapp', $phone, $message, true);

            return ['success' => true, 'message_id' => $result->sid, 'status' => $result->status];
        } catch (\Exception $e) {
            Log::error('Failed to send payment reminder WhatsApp', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            $this->logSms($companyId, $invoice->id, 'payment_reminder_whatsapp', $phoneNumber ?? 'unknown', '', false, $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function sendPaymentReminder(Invoice $invoice, $companyId, $phoneNumber = null)
    {
        if (!$this->enabled) {
            return ['success' => false, 'message' => 'SMS service not enabled'];
        }

        try {
            $phone = $phoneNumber ?? $this->getCustomerPhone($invoice);

            if (!$phone) {
                return ['success' => false, 'message' => 'No phone number available'];
            }

            $remainingDue = $this->calculateRemainingDue($invoice->id);
            $daysOverdue = max(0, now()->diffInDays($invoice->due_date));

            $message = $this->formatPaymentReminderMessage($invoice, $remainingDue, $daysOverdue);

            $result = $this->client->messages->create(
                $phone,
                [
                    'from' => $this->twilioPhone,
                    'body' => $message
                ]
            );

            $this->logSms($companyId, $invoice->id, 'payment_reminder', $phone, $message, true);

            return [
                'success' => true,
                'message_id' => $result->sid,
                'status' => $result->status
            ];
        } catch (\Exception $e) {
            Log::error('Failed to send payment reminder SMS', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);

            $this->logSms($companyId, $invoice->id, 'payment_reminder', $phoneNumber ?? 'unknown', '', false, $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function sendLowStockAlert($itemType, $itemName, $currentStock, $phoneNumber, $companyId)
    {
        if (!$this->enabled) {
            return ['success' => false, 'message' => 'SMS service not enabled'];
        }

        try {
            $message = $this->formatLowStockMessage($itemName, $currentStock);

            $result = $this->client->messages->create(
                $phoneNumber,
                [
                    'from' => $this->twilioPhone,
                    'body' => $message
                ]
            );

            $this->logSms($companyId, null, 'low_stock_alert', $phoneNumber, $message, true);

            return [
                'success' => true,
                'message_id' => $result->sid,
                'status' => $result->status
            ];
        } catch (\Exception $e) {
            Log::error('Failed to send low stock alert SMS', [
                'item_name' => $itemName,
                'error' => $e->getMessage()
            ]);

            $this->logSms($companyId, null, 'low_stock_alert', $phoneNumber, '', false, $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function sendProductionAlert($batchId, $stage, $phoneNumber, $companyId)
    {
        if (!$this->enabled) {
            return ['success' => false, 'message' => 'SMS service not enabled'];
        }

        try {
            $message = $this->formatProductionAlertMessage($batchId, $stage);

            $result = $this->client->messages->create(
                $phoneNumber,
                [
                    'from' => $this->twilioPhone,
                    'body' => $message
                ]
            );

            $this->logSms($companyId, null, 'production_alert', $phoneNumber, $message, true);

            return [
                'success' => true,
                'message_id' => $result->sid,
                'status' => $result->status
            ];
        } catch (\Exception $e) {
            Log::error('Failed to send production alert SMS', [
                'batch_id' => $batchId,
                'error' => $e->getMessage()
            ]);

            $this->logSms($companyId, null, 'production_alert', $phoneNumber, '', false, $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function sendCustomMessage($phoneNumber, $message, $companyId)
    {
        if (!$this->enabled) {
            return ['success' => false, 'message' => 'SMS service not enabled'];
        }

        try {
            if (strlen($message) > 160) {
                $message = substr($message, 0, 157) . '...';
            }

            $result = $this->client->messages->create(
                $phoneNumber,
                [
                    'from' => $this->twilioPhone,
                    'body' => $message
                ]
            );

            $this->logSms($companyId, null, 'custom_message', $phoneNumber, $message, true);

            return [
                'success' => true,
                'message_id' => $result->sid,
                'status' => $result->status
            ];
        } catch (\Exception $e) {
            Log::error('Failed to send custom SMS', ['error' => $e->getMessage()]);

            $this->logSms($companyId, null, 'custom_message', $phoneNumber, '', false, $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function validatePhoneNumber($phoneNumber)
    {
        // Basic validation - check if it looks like a phone number
        $cleaned = preg_replace('/[^0-9+]/', '', $phoneNumber);

        if (strlen($cleaned) < 10 || strlen($cleaned) > 15) {
            return ['valid' => false, 'message' => 'Phone number must be 10-15 digits'];
        }

        if (!preg_match('/^\+?[1-9]\d{1,14}$/', $cleaned)) {
            return ['valid' => false, 'message' => 'Invalid phone number format'];
        }

        return ['valid' => true, 'normalized' => $cleaned];
    }

    public function getSmsLogs($companyId, $limit = 50)
    {
        return SmsLog::where('company_id', $companyId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    protected function formatPaymentReminderMessage($invoice, $remainingDue, $daysOverdue)
    {
        if ($daysOverdue > 14) {
            return "URGENT: Invoice #{$invoice->invoice_no} is {$daysOverdue} days overdue. Amount due: ₹{$remainingDue}. Please pay immediately to avoid penalties.";
        } elseif ($daysOverdue > 7) {
            return "REMINDER: Invoice #{$invoice->invoice_no} is {$daysOverdue} days overdue. Amount due: ₹{$remainingDue}. Please pay at your earliest convenience.";
        } else {
            return "Payment Reminder: Invoice #{$invoice->invoice_no} is now overdue. Amount due: ₹{$remainingDue}. Please arrange payment.";
        }
    }

    protected function formatLowStockMessage($itemName, $currentStock)
    {
        return "⚠️ Low Stock Alert: {$itemName} stock is now at {$currentStock} units. Please reorder to avoid production delays.";
    }

    protected function formatProductionAlertMessage($batchId, $stage)
    {
        return "📦 Production Update: Batch #{$batchId} has completed {$stage} stage. Next stage starting soon.";
    }

    protected function getCustomerPhone(Invoice $invoice)
    {
        return $invoice->dispatch?->batch?->order?->customer?->phone ?? null;
    }

    protected function calculateRemainingDue($invoiceId)
    {
        $invoice = \App\Models\Invoice::findOrFail($invoiceId);
        $total = $invoice->subtotal + ($invoice->taxCalculation?->tax_amount ?? 0);
        $paid = \App\Models\PaymentTransaction::where('invoice_id', $invoiceId)->sum('amount');

        return max(0, $total - $paid);
    }

    protected function logSms($companyId, $invoiceId, $type, $phoneNumber, $message, $success = true, $errorMessage = null)
    {
        try {
            SmsLog::create([
                'company_id' => $companyId,
                'invoice_id' => $invoiceId,
                'type' => $type,
                'phone_number' => $this->maskPhoneNumber($phoneNumber),
                'message' => substr($message, 0, 160),
                'success' => $success,
                'error_message' => $errorMessage,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log SMS', ['error' => $e->getMessage()]);
        }
    }

    protected function maskPhoneNumber($phone)
    {
        if (!$phone || strlen($phone) < 4) {
            return $phone;
        }
        return substr($phone, 0, 3) . '****' . substr($phone, -2);
    }

    public function isEnabled()
    {
        return $this->enabled;
    }
}
