<?php

namespace App\Services;

/**
 * Minimal Razorpay REST client using PHP HTTP streams (no SDK / no curl).
 * Only what billing needs: create an order, verify a payment signature, and
 * verify a webhook signature.
 *
 * Enabled when config('services.razorpay.enabled') and keys are set.
 */
class RazorpayClient
{
    private ?string $keyId;
    private ?string $keySecret;
    private bool $enabled;

    public function __construct()
    {
        $this->keyId     = config('services.razorpay.key_id');
        $this->keySecret = config('services.razorpay.key_secret');
        $this->enabled   = (bool) config('services.razorpay.enabled', false) && $this->keyId && $this->keySecret;
    }

    public function isEnabled(): bool { return $this->enabled; }
    public function keyId(): ?string { return $this->keyId; }

    /**
     * Create an order. Amount is in paise (₹1 = 100).
     * @return array Razorpay order (id, amount, currency, ...)
     */
    public function createOrder(int $amountPaise, string $receipt, array $notes = []): array
    {
        $payload = json_encode([
            'amount'   => $amountPaise,
            'currency' => 'INR',
            'receipt'  => $receipt,
            'notes'    => $notes,
        ]);

        $auth    = base64_encode("{$this->keyId}:{$this->keySecret}");
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Authorization: Basic ' . $auth,
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($payload),
                ]),
                'content'       => $payload,
                'ignore_errors' => true,
                'timeout'       => 20,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $raw  = @file_get_contents('https://api.razorpay.com/v1/orders', false, $context);
        $data = $raw ? (json_decode($raw, true) ?? []) : [];
        $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;

        if ($status < 200 || $status >= 300 || empty($data['id'])) {
            $err = $data['error']['description'] ?? ('HTTP ' . $status);
            throw new \RuntimeException("Razorpay order failed: {$err}");
        }
        return $data;
    }

    /** Verify the checkout payment signature returned by Razorpay Checkout. */
    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->keySecret);
        return hash_equals($expected, $signature);
    }

    /** Verify a webhook payload signature. */
    public function verifyWebhookSignature(string $rawBody, string $signature, string $webhookSecret): bool
    {
        $expected = hash_hmac('sha256', $rawBody, $webhookSecret);
        return hash_equals($expected, $signature);
    }
}
