<?php

namespace App\Services;

/**
 * Sends Twilio SMS / WhatsApp messages using PHP's built-in HTTP stream wrapper
 * (file_get_contents + stream_context_create) — no curl extension required.
 *
 * Works as long as allow_url_fopen = On (default PHP setting) and the server
 * can reach api.twilio.com over HTTPS (needs openssl extension, always on in XAMPP).
 */
class TwilioStreamClient
{
    public function __construct(
        private string $accountSid,
        private string $authToken,
    ) {}

    /**
     * Send an SMS message.
     * @param string $to   E.164 format e.g. +919722915105
     * @param string $from Twilio From number in E.164
     * @param string $body Message text
     */
    public function sendSms(string $to, string $from, string $body): array
    {
        return $this->post($to, $from, $body);
    }

    /**
     * Send a WhatsApp message via Twilio WhatsApp channel.
     * @param string $to   E.164 format — "whatsapp:" prefix added automatically
     * @param string $from Twilio WhatsApp-enabled number in E.164
     * @param string $body Message text
     */
    public function sendWhatsApp(string $to, string $from, string $body): array
    {
        return $this->post('whatsapp:' . $to, 'whatsapp:' . $from, $body);
    }

    private function post(string $to, string $from, string $body): array
    {
        $url     = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";
        $payload = http_build_query(['To' => $to, 'From' => $from, 'Body' => $body]);
        $auth    = base64_encode("{$this->accountSid}:{$this->authToken}");

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Authorization: Basic ' . $auth,
                    'Content-Type: application/x-www-form-urlencoded',
                    'Content-Length: ' . strlen($payload),
                    'Accept: application/json',
                ]),
                'content'       => $payload,
                'ignore_errors' => true,   // capture 4xx/5xx body too
                'timeout'       => 20,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw  = @file_get_contents($url, false, $context);
        $data = $raw ? (json_decode($raw, true) ?? []) : [];

        // $http_response_header is set by file_get_contents
        $statusLine = $http_response_header[0] ?? 'HTTP/1.1 0';
        preg_match('/\s(\d{3})\s/', $statusLine, $m);
        $status = (int) ($m[1] ?? 0);

        if ($status >= 200 && $status < 300) {
            return ['success' => true, 'sid' => $data['sid'] ?? null, 'status' => $data['status'] ?? 'queued'];
        }

        $errMsg = $data['message'] ?? $data['error'] ?? ('HTTP ' . $status);
        throw new \RuntimeException("Twilio API error ({$status}): {$errMsg}");
    }
}
