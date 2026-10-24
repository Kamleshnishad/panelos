<?php

namespace Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for Stripe's HTTP layer so the gateway code is tested for what WE do — the amount in paise, the
 * currency, the metadata, how a response and an error are handled — without a network or real keys. The
 * old tests called the live Stripe API and could never pass in CI.
 */
class FakeStripeHttpClient implements ClientInterface
{
    /** @var array<int, array{method:string, path:string, params:array}> */
    public array $requests = [];
    public bool $fail = false;

    public function request($method, $absUrl, $headers, $params, $hasFile)
    {
        $path = parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['method' => strtolower($method), 'path' => $path, 'params' => $params];

        if ($this->fail) {
            return [json_encode(['error' => ['type' => 'api_error', 'message' => 'Stripe is down']]), 500, []];
        }

        $body = match (true) {
            $path === '/v1/checkout/sessions' => ['id' => 'cs_test_1', 'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.test/c/pay/cs_test_1', 'expires_at' => time() + 3600],
            $path === '/v1/payment_intents' => ['id' => 'pi_test_1', 'object' => 'payment_intent',
                'client_secret' => 'pi_test_1_secret_abc', 'amount' => $params['amount'] ?? 0, 'status' => 'requires_payment_method'],
            str_starts_with($path, '/v1/payment_intents/') => ['id' => basename($path), 'object' => 'payment_intent', 'status' => 'succeeded'],
            $path === '/v1/payment_links' => ['id' => 'plink_test_1', 'object' => 'payment_link', 'url' => 'https://buy.stripe.test/plink_test_1'],
            default => ['error' => ['type' => 'invalid_request_error', 'message' => "Unexpected request to {$path}"]],
        };

        return [json_encode($body), isset($body['error']) ? 404 : 200, []];
    }

    /** The last request sent to a path, or null. */
    public function last(string $path): ?array
    {
        foreach (array_reverse($this->requests) as $r) {
            if ($r['path'] === $path) return $r;
        }
        return null;
    }
}
