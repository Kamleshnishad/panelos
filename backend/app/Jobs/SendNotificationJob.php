<?php

namespace App\Jobs;

use App\Models\NotificationSetting;
use App\Models\NotificationLog;
use App\Services\TwilioStreamClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * OPS-H1 — sends one WhatsApp/SMS notification off the web request, so the
 * (up-to-20s) Twilio call never blocks order-confirm / dispatch / payment flows.
 * Self-contained (scalar payload only): re-resolves credentials at run time and
 * records the outcome to notification_logs (OPS-H3). Fire-and-forget — a failure
 * is logged, not re-thrown, so it neither duplicates a send nor floods failed_jobs.
 */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $companyId,
        public string $phone,
        public string $message,
        public string $type,
    ) {}

    public function handle(): void
    {
        $ns    = NotificationSetting::forCompany($this->companyId);
        $sid   = $ns->resolvedSid();
        $token = $ns->resolvedToken();
        if (!$sid || !$token) return;

        $to = $this->e164($this->phone);
        try {
            $twilio = new TwilioStreamClient($sid, $token);

            if ($ns->isWhatsappReady()) {
                $twilio->sendWhatsApp($to, $ns->resolvedWhatsappFrom(), $this->message);
                $this->logDelivery('whatsapp', 'sent');
                return;
            }
            if ($ns->isSmsReady()) {
                $twilio->sendSms($to, $ns->resolvedFromNumber(), $this->message);
                $this->logDelivery('sms', 'sent');
            }
        } catch (\Throwable $e) {
            Log::error('Notification send failed', ['type' => $this->type, 'error' => $e->getMessage(), 'company' => $this->companyId]);
            $this->logDelivery($ns->isWhatsappReady() ? 'whatsapp' : 'sms', 'failed', $e->getMessage());
            // Do NOT re-throw: Twilio sends aren't idempotent, so retrying could
            // duplicate a message that actually went out. The failure is visible
            // in notification_logs.
        }
    }

    private function logDelivery(string $channel, string $status, ?string $error = null): void
    {
        try {
            NotificationLog::create([
                'company_id' => $this->companyId,
                'channel'    => $channel,
                'recipient'  => $this->phone,
                'type'       => $this->type,
                'status'     => $status,
                'error'      => $error ? mb_substr($error, 0, 1000) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable) { /* visibility must never break the job */ }
    }

    private function e164(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '+')) return '+' . $digits;
        if (strlen($digits) === 10) return '+91' . $digits;
        return '+' . $digits;
    }
}
