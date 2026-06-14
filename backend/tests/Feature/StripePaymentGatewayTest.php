<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripePaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
    protected $dispatch;
    protected $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Test Company']);
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id
        ]);

        $this->actingAs($this->user);

        $this->dispatch = Dispatch::factory()->for($this->company)->create();
        $invoiceResponse = $this->postJson('/api/invoices/from-dispatch', [
            'dispatch_id' => $this->dispatch->id
        ]);
        $this->invoice = $this->company->invoices()->first();
    }

    public function test_create_checkout_session_endpoint()
    {
        $response = $this->postJson("/api/invoices/{$this->invoice->id}/payment/checkout-session");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'session_id',
                'payment_url',
                'expires_at'
            ]);
    }

    public function test_create_payment_intent_endpoint()
    {
        $response = $this->postJson("/api/invoices/{$this->invoice->id}/payment/intent");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'client_secret',
                'intent_id',
                'amount'
            ]);

        $this->assertIsString($response['client_secret']);
        $this->assertIsString($response['intent_id']);
        $this->assertEquals($this->invoice->getTotal(), $response['amount']);
    }

    public function test_get_payment_link_endpoint()
    {
        $response = $this->getJson("/api/invoices/{$this->invoice->id}/payment-link");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'payment_link',
                'link_id'
            ]);
    }

    public function test_create_checkout_session_with_invalid_invoice()
    {
        $response = $this->postJson('/api/invoices/99999/payment/checkout-session');

        $response->assertStatus(404);
    }

    public function test_payment_intent_with_correct_amount()
    {
        $response = $this->postJson("/api/invoices/{$this->invoice->id}/payment/intent");

        $response->assertStatus(200);
        $this->assertEquals($this->invoice->getTotal(), $response['amount']);
    }

    public function test_webhook_signature_verification()
    {
        $gatewayService = new PaymentGatewayService();

        // Test with invalid signature
        $result = $gatewayService->verifyWebhookSignature('payload', 'invalid_signature');
        $this->assertFalse($result);
    }

    public function test_payment_intent_confirmation()
    {
        $response = $this->postJson("/api/invoices/{$this->invoice->id}/payment/intent");
        $intentId = $response['intent_id'];

        $confirmResponse = $this->postJson('/payments/intent/confirm', [
            'intent_id' => $intentId,
            'payment_method_id' => 'pm_test'
        ]);

        $confirmResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'status'
            ]);
    }

    public function test_non_authenticated_webhook_access()
    {
        $this->actingAs(null);

        $response = $this->postJson('/api/webhooks/stripe', [
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => []]
        ]);

        // Webhook should be accessible without auth
        // but signature verification should fail
        $response->assertStatus(401); // Invalid signature expected
    }

    public function test_stripe_currency_conversion()
    {
        $gatewayService = new PaymentGatewayService();

        // Test currency conversion using reflection
        $reflection = new \ReflectionMethod($gatewayService, 'convertToStripeAmount');
        $reflection->setAccessible(true);

        $amount = 100.50;
        $stripeAmount = $reflection->invoke($gatewayService, $amount);

        $this->assertEquals(10050, $stripeAmount);
    }

    public function test_payment_gateway_service_customer_email()
    {
        $gatewayService = new PaymentGatewayService();

        // Test email retrieval using reflection
        $reflection = new \ReflectionMethod($gatewayService, 'getCustomerEmail');
        $reflection->setAccessible(true);

        $email = $reflection->invoke($gatewayService, $this->invoice);
        $this->assertIsString($email);
    }

    public function test_multiple_payment_methods_support()
    {
        // Test card payment intent
        $cardResponse = $this->postJson("/api/invoices/{$this->invoice->id}/payment/intent");
        $this->assertTrue($cardResponse['success']);

        // Test payment link
        $linkResponse = $this->getJson("/api/invoices/{$this->invoice->id}/payment-link");
        $this->assertTrue($linkResponse['success']);

        // Test checkout session
        $checkoutResponse = $this->postJson("/api/invoices/{$this->invoice->id}/payment/checkout-session");
        $this->assertTrue($checkoutResponse['success']);
    }
}
