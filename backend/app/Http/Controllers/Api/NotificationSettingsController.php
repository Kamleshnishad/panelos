<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationSetting;
use App\Services\SmsService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class NotificationSettingsController extends Controller
{
    use ApiResponse;

    public function show(Request $r)
    {
        $s = NotificationSetting::forCompany($r->user()->company_id);
        // Mask token in response — send '*' so frontend knows it's set
        $data = $s->toArray();
        if (!empty($data['twilio_auth_token'])) {
            $data['twilio_auth_token'] = str_repeat('*', 8);
            $data['token_is_set'] = true;
        } else {
            $data['token_is_set'] = false;
        }
        $data['sms_ready']       = $s->isSmsReady();
        $data['whatsapp_ready']  = $s->isWhatsappReady();
        return $this->successResponse($data, 'Notification settings retrieved');
    }

    public function update(Request $r)
    {
        $data = $r->validate([
            'twilio_account_sid'      => 'nullable|string|max:100',
            'twilio_auth_token'       => 'nullable|string|max:100',
            'twilio_from_number'      => 'nullable|string|max:20',
            'sms_enabled'             => 'nullable|boolean',
            'whatsapp_from'           => 'nullable|string|max:20',
            'whatsapp_enabled'        => 'nullable|boolean',
            'notify_payment_due'      => 'nullable|boolean',
            'payment_due_days_before' => 'nullable|integer|min:1|max:30',
            'notify_payment_overdue'  => 'nullable|boolean',
            'notify_low_stock'        => 'nullable|boolean',
            'notify_order_confirmed'  => 'nullable|boolean',
            'notify_dispatch_done'    => 'nullable|boolean',
            'admin_phone'             => 'nullable|string|max:20',
        ]);

        $s = NotificationSetting::forCompany($r->user()->company_id);

        // Don't overwrite token with the masked placeholder
        $token = $data['twilio_auth_token'] ?? null;
        if ($token === null || $token === '' || str_starts_with($token, '*') || str_starts_with($token, '•')) {
            unset($data['twilio_auth_token']);
        }

        // Save: only skip truly null values (allow empty strings to clear fields)
        $save = array_filter($data, fn ($v) => $v !== null);
        $s->update($save);

        return $this->successResponse([
            'sms_ready'      => $s->fresh()->isSmsReady(),
            'whatsapp_ready' => $s->fresh()->isWhatsappReady(),
        ], 'Notification settings saved');
    }

    /**
     * Send a test SMS or WhatsApp message to confirm credentials work.
     */
    public function testSend(Request $r)
    {
        $data = $r->validate([
            'channel' => 'required|in:sms,whatsapp',
            'phone'   => 'required|string|max:20',
        ]);

        $s = NotificationSetting::forCompany($r->user()->company_id);

        if ($data['channel'] === 'sms' && !$s->isSmsReady()) {
            return $this->errorResponse([], 'SMS is not configured or disabled. Save valid Twilio credentials and enable SMS first.', 'SMS_NOT_READY', 422);
        }
        if ($data['channel'] === 'whatsapp' && !$s->isWhatsappReady()) {
            return $this->errorResponse([], 'WhatsApp is not configured or disabled. Save valid credentials and enable WhatsApp first.', 'WA_NOT_READY', 422);
        }

        try {
            $twilio = new \App\Services\TwilioStreamClient($s->resolvedSid(), $s->resolvedToken());
            $r->user()->loadMissing('company');
            $companyName = $r->user()->company?->name ?? 'PanelOS';
            $msg  = "PanelOS test message from {$companyName}. Notifications are working! ✅";
            $phone = $data['phone'];

            if ($data['channel'] === 'sms') {
                $result = $twilio->sendSms($phone, $s->resolvedFromNumber(), $msg);
            } else {
                $result = $twilio->sendWhatsApp($phone, $s->resolvedWhatsappFrom(), $msg);
            }

            return $this->successResponse($result, 'Test message sent successfully');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'Failed to send: ' . $e->getMessage(), 'TWILIO_ERROR', 400);
        }
    }
}
