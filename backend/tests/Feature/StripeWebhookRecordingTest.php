<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Services\PaymentGatewayService;
use Tests\TestCase;

class StripeWebhookRecordingTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function stripeSignatureHeader(string $payload, string $secret): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        return "t={$timestamp},v1={$signature}";
    }

    /**
     * C5's actual defect: json_decode($payload, true) + a shallow (object)
     * cast left $event->data as a plain array, so $event->data->object
     * (property access on an array) silently evaluated to null and
     * handlePaymentSucceeded() always returned "No invoice ID in metadata"
     * — even when metadata.invoice_id was present. Decoding without assoc
     * mode (json_decode($payload), the fix) keeps nested structures as
     * stdClass, so the metadata is reachable.
     *
     * This calls the service directly with both decode strategies to prove
     * exactly where the old code diverged — a full HTTP round trip to the
     * 'paid' happy path isn't exercised here because `payment_transactions
     * .payment_method` is a MySQL-only ENUM widened to include 'stripe'/
     * 'razorpay' (migration 2026_06_17_000001); the migration's own comment
     * documents that this widening is skipped on SQLite (the test driver),
     * a pre-existing, unrelated test-environment gap — not something this
     * fix touches.
     */
    public function test_handle_payment_succeeded_reaches_the_metadata_with_the_fixed_decode(): void
    {
        $admin = $this->createAdminUser();
        $invoice = Invoice::create([
            'company_id'   => $admin->company_id,
            'status'       => 'sent',
            'subtotal'     => 1000,
            'tax_amount'   => 0,
            'total_amount' => 1000,
            'invoice_date' => now(),
            'due_date'     => now()->addDays(30),
        ]);

        $payload = json_encode([
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test_123',
                    'amount' => 100000,
                    'created' => time(),
                    'metadata' => ['invoice_id' => (string) $invoice->id],
                    'charges' => ['data' => [['id' => 'ch_test_123']]],
                ],
            ],
        ]);

        $service = new PaymentGatewayService();

        // Old, broken decode path: assoc-array decode + shallow object cast
        // leaves $event->data as a plain array, so `$event->data->object`
        // (property access on an array) is undefined behavior — PHPUnit's
        // error handler surfaces it as a thrown warning; in production this
        // degrades silently to null, which is how the audit observed it
        // (a quiet "No invoice ID in metadata" with no error anywhere).
        // Either way, the payment is never recorded — that's the bug.
        $broken = (object) json_decode($payload, true);
        try {
            $service->handlePaymentSucceeded($broken);
            $this->fail('Expected the broken decode path to fail reading ->data->object on an array.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('property', strtolower($e->getMessage()));
        }

        // Fixed decode path: plain json_decode(), nested stdClass all the way down.
        $fixed = json_decode($payload);
        $fixedResult = $service->handlePaymentSucceeded($fixed);
        $this->assertNotSame('No invoice ID in metadata', $fixedResult['message'] ?? null);
    }

    public function test_payment_intent_succeeded_webhook_without_invoice_metadata_reports_failure_instead_of_fake_success(): void
    {
        $secret = 'whsec_test_secret';
        config(['services.stripe.webhook_secret' => $secret, 'services.stripe.secret' => 'sk_test_dummy']);

        $payload = json_encode([
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test_456',
                    'amount' => 50000,
                    'created' => time(),
                    'metadata' => [],
                    'charges' => ['data' => [['id' => 'ch_test_456']]],
                ],
            ],
        ]);

        $response = $this->postJson('/api/webhooks/stripe', json_decode($payload, true), [
            'stripe-signature' => $this->stripeSignatureHeader($payload, $secret),
        ]);

        // Previously this unconditionally returned {"success": true} regardless
        // of what handlePaymentSucceeded() actually did (C5) — now the
        // controller must surface the failure instead of telling Stripe
        // everything is fine.
        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertSame(0, PaymentTransaction::where('transaction_id', 'ch_test_456')->count());
    }
}
