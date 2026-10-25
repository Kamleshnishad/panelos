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
        $data['mail']              = \App\Support\MailStatus::describe($r->user()->company_id);
        // The company's own mailbox. The password is never sent back, only whether one is saved.
        $data['smtp_password_set']       = !empty($s->getAttribute('smtp_password'));
        $data['notif_smtp_password_set'] = !empty($s->getAttribute('notif_smtp_password'));
        // Which mailbox each kind of customer e-mail uses (the admin's choice, else the default), and the kinds themselves.
        $company = $r->user()->company_id;
        $data['mail_routing'] = collect(\App\Support\CompanyMail::TYPES)->map(fn ($m, $type) => \App\Support\CompanyMail::kindFor($company, $type))->all();
        $data['mail_types']   = collect(\App\Support\CompanyMail::TYPES)->map(fn ($m, $type) => ['type' => $type, 'label' => $m['label'], 'default' => $m['default']])->values()->all();
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
            'notify_lead_followups'   => 'nullable|boolean',
            'chase_quotations'        => 'nullable|boolean',
            'lead_stale_days'         => 'nullable|integer|min:0|max:90',
            'lead_overdue_alert_days' => 'nullable|integer|min:1|max:60',
            'admin_phone'             => 'nullable|string|max:20',
            'email_payment_reminders' => 'nullable|boolean',
            'mail_routing'            => 'nullable|array',
            'mail_routing.*'          => 'in:main,notifications',
        ] + $this->mailboxRules('smtp_') + $this->mailboxRules('notif_smtp_'));

        // Which mailbox each kind of e-mail uses: only known kinds, merged over what is saved so a partial save keeps the rest.
        $current = NotificationSetting::forCompany($r->user()->company_id);
        if (array_key_exists('mail_routing', $data)) {
            $known = array_keys(\App\Support\CompanyMail::TYPES);
            $data['mail_routing'] = array_merge($current->mail_routing ?? [], array_intersect_key($data['mail_routing'] ?? [], array_flip($known)));
        }

        // A blank password keeps the saved one; it is replaced only when a new one is typed, or removed on request.
        $cleared = [];
        $labels = ['smtp_' => 'main mailbox', 'notif_smtp_' => 'notifications mailbox'];
        foreach (['smtp_', 'notif_smtp_'] as $p) {
            if (!empty($data["clear_{$p}password"])) {
                $data["{$p}password"] = null;
                $cleared[] = $p;
            } elseif (($data["{$p}password"] ?? '') === '') {
                unset($data["{$p}password"]);
            }
            unset($data["clear_{$p}password"]);

            // Switching a mailbox on needs everything it takes to log in and to say who the mail is from.
            if (!empty($data["{$p}enabled"])) {
                $merged = fn ($k) => array_key_exists($p . $k, $data) ? $data[$p . $k] : $current->getAttribute($p . $k);
                $missing = collect(['host' => 'mail server', 'port' => 'port', 'username' => 'user name', 'password' => 'password', 'from_address' => '"send as" address'])
                    ->filter(fn ($label, $k) => empty($merged($k)) || (in_array($p, $cleared, true) && $k === 'password'))->values();
                if ($missing->isNotEmpty()) {
                    throw \Illuminate\Validation\ValidationException::withMessages(["{$p}enabled" => "To switch on the {$labels[$p]}, fill in: " . $missing->implode(', ') . '.']);
                }
            }
        }

        $s = NotificationSetting::forCompany($r->user()->company_id);

        // Don't overwrite token with the masked placeholder
        $token = $data['twilio_auth_token'] ?? null;
        if ($token === null || $token === '' || str_starts_with($token, '*') || str_starts_with($token, '•')) {
            unset($data['twilio_auth_token']);
        }

        // Save: only skip truly null values (allow empty strings to clear fields)
        $save = array_filter($data, fn ($v) => $v !== null);
        $s->update($save);
        foreach ($cleared as $p) {
            $s->forceFill(["{$p}password" => null, "{$p}enabled" => false])->save();   // no password, so the mailbox cannot stay on
        }

        return $this->successResponse([
            'sms_ready'      => $s->fresh()->isSmsReady(),
            'whatsapp_ready' => $s->fresh()->isWhatsappReady(),
        ], 'Notification settings saved');
    }

    /**
     * Send a test e-mail to the signed-in admin, synchronously, so a wrong SMTP setting shows up here as a readable
     * error instead of silently failing later in a queue.
     */
    public function testEmail(Request $r)
    {
        $r->validate(['mailbox' => 'nullable|in:main,notifications', 'to' => 'nullable|email|max:190']);
        $kind = $r->input('mailbox') === 'notifications' ? \App\Support\CompanyMailbox::NOTIFICATIONS : \App\Support\CompanyMailbox::MAIN;
        $status = \App\Support\MailStatus::describe($r->user()->company_id);
        // The test uses exactly the mailbox whose button was pressed. For the main one, no mailbox of its own means the server's mailer.
        $own = \App\Support\CompanyMail::exactFor($r->user()->company_id, $kind);
        if (!$own && $kind === \App\Support\CompanyMailbox::NOTIFICATIONS) {
            return $this->errorResponse([], 'The notifications mailbox is not set up yet. Fill in every field, switch it on and save, then test it.', 'MAIL_NOT_SET_UP', 422);
        }
        if (!$status['delivers'] && !$own) {
            return $this->errorResponse(['mail' => $status], $status['headline'], 'MAIL_NOT_SET_UP', 422);
        }

        // The test goes to the address typed on the screen, so nobody's real inbox is disturbed; blank = the admin's own login address.
        // Another recipient is allowed only through the company's OWN mailbox: through the PanelOS server's mailer it would let any
        // tenant send mail from our address to anyone.
        $custom = trim((string) $r->input('to'));
        if ($custom !== '' && !$own) {
            return $this->errorResponse([], 'Testing another address needs your own mailbox switched on and saved. Without it, the test can only go to your own login address.', 'MAIL_TEST_TARGET_NOT_ALLOWED', 422);
        }
        $to = $custom !== '' ? $custom : $r->user()->email;
        $company = $r->user()->company?->name ?? 'PanelOS';
        try {
            $body = "This is a test e-mail from {$company} on PanelOS.\n\nIf you can read it, e-mail is working: invoice, payment and reminder e-mails will be delivered.";
            if ($own) {
                // Through the company's own mailbox, synchronously, so a wrong password is shown here and now.
                [$address, $name] = \App\Support\CompanyMail::from($own);
                \App\Support\CompanyMail::mailer($own)->raw($body, fn ($m) => $m->to($to)->from($address, $name)->subject('PanelOS test e-mail'));
            } else {
                \Illuminate\Support\Facades\Mail::raw($body, fn ($m) => $m->to($to)->subject('PanelOS test e-mail'));
            }
        } catch (\Throwable $e) {
            return $this->errorResponse(['mail' => $status], 'The mail server refused it: ' . $e->getMessage(), 'MAIL_SEND_FAILED', 422);
        }

        return $this->successResponse(['to' => $to, 'mail' => $status], "Test e-mail sent to {$to}. Check that inbox (and spam).");
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

    /** Validation for one of the two mailboxes; $p is the column prefix ('smtp_' or 'notif_smtp_'). */
    private function mailboxRules(string $p): array
    {
        return [
            "{$p}enabled"       => 'nullable|boolean',
            "{$p}host"          => ['nullable', 'string', 'max:150', function ($attr, $value, $fail) {
                if ($value !== null && $value !== '' && ($why = \App\Support\SmtpHostGuard::problemWithHost($value))) $fail($why);
            }],
            "{$p}port"          => ['nullable', 'integer', 'in:' . implode(',', \App\Support\SmtpHostGuard::PORTS)],
            "{$p}encryption"    => 'nullable|in:ssl,tls',
            "{$p}username"      => 'nullable|string|max:190',
            "{$p}password"      => 'nullable|string|max:300',
            "{$p}from_address"  => 'nullable|email|max:190',
            "{$p}from_name"     => 'nullable|string|max:150',
            "clear_{$p}password" => 'nullable|boolean',
        ];
    }
}
