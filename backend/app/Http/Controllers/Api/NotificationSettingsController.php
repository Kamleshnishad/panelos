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
        // toArray() omits twilio_auth_token ($hidden) — read raw attribute to detect it
        $hasToken = !empty($s->getAttribute('twilio_auth_token'));

        $data = $s->toArray();
        $data['twilio_auth_token'] = $hasToken ? str_repeat('*', 8) : '';
        $data['token_is_set']      = $hasToken;
        $data['sms_ready']         = $s->isSmsReady();
        $data['whatsapp_ready']    = $s->isWhatsappReady();
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
            $msg = $e->getMessage();
            $waFrom = (string) $s->resolvedWhatsappFrom();
            // Friendly hints for the most common Twilio setup mistakes
            if (stripos($msg, 'could not find a Channel') !== false) {
                if (str_contains($waFrom, '14155238886')) {
                    $msg .= '  →  Activate the WhatsApp Sandbox first: Twilio Console → Messaging → Try it out → Send a WhatsApp message. '
                          . 'Confirm the sandbox number shown there matches your "WhatsApp From", then the recipient must send "join <code>" to it.';
                } else {
                    $msg .= '  →  For WhatsApp sandbox, the "WhatsApp From" must be +14155238886 (not your SMS number).';
                }
            } elseif (stripos($msg, 'not a valid phone number') !== false || stripos($msg, "is not currently reachable") !== false || stripos($msg, 'not currently opted in') !== false) {
                $msg .= '  →  The recipient must first send "join <code>" to ' . ($waFrom ?: '+14155238886') . ' on WhatsApp to opt into the sandbox.';
            } elseif (stripos($msg, 'unverified') !== false) {
                $msg .= '  →  On a Twilio trial, the recipient number must be verified in your Twilio Console first.';
            }
            return $this->errorResponse(['error' => $msg], 'Failed to send: ' . $msg, 'TWILIO_ERROR', 400);
        }
    }

    /** Recent delivery outcomes + 7-day failure count for operator visibility (OPS-H3). */
    public function logs(Request $r)
    {
        $cid = $r->user()->company_id;
        $logs = \App\Models\NotificationLog::where('company_id', $cid)
            ->orderByDesc('id')->limit(50)->get();
        $failed7d = \App\Models\NotificationLog::where('company_id', $cid)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
        return $this->successResponse([
            'logs'      => $logs,
            'failed_7d' => $failed7d,
        ], 'Notification logs');
    }
}
