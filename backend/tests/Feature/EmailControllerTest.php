<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dispatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;

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
    }

    public function test_send_invoice_email_endpoint()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoiceResponse = $this->postJson('/api/invoices/from-dispatch', [
            'dispatch_id' => $dispatch->id
        ]);

        $invoice = $this->company->invoices()->first();
        $this->invoiceService = app(\App\Services\InvoiceService::class);
        $this->invoiceService->sendInvoice($invoice->id);

        $response = $this->postJson("/api/invoices/{$invoice->id}/send-email");

        $response->assertStatus(400) // Expected to fail without proper email config
            ->assertJsonPath('success', false);
    }

    public function test_email_preview_endpoint()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoiceResponse = $this->postJson('/api/invoices/from-dispatch', [
            'dispatch_id' => $dispatch->id
        ]);

        $invoice = $this->company->invoices()->first();

        $response = $this->getJson("/api/invoices/{$invoice->id}/email-preview/invoice_sent");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'subject',
                    'preview'
                ]
            ]);
    }

    public function test_email_preview_payment_reminder()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoiceResponse = $this->postJson('/api/invoices/from-dispatch', [
            'dispatch_id' => $dispatch->id
        ]);

        $invoice = $this->company->invoices()->first();
        $invoiceService = app(\App\Services\InvoiceService::class);
        $invoiceService->sendInvoice($invoice->id);

        $response = $this->getJson("/api/invoices/{$invoice->id}/email-preview/payment_reminder");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_send_payment_reminder_endpoint()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoiceResponse = $this->postJson('/api/invoices/from-dispatch', [
            'dispatch_id' => $dispatch->id
        ]);

        $invoice = $this->company->invoices()->first();

        $response = $this->postJson("/api/invoices/{$invoice->id}/send-payment-reminder");

        $response->assertStatus(400) // Expected to fail without proper email config
            ->assertJsonPath('success', false);
    }

    public function test_send_payment_confirmation_endpoint()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoiceResponse = $this->postJson('/api/invoices/from-dispatch', [
            'dispatch_id' => $dispatch->id
        ]);

        $invoice = $this->company->invoices()->first();
        $invoiceService = app(\App\Services\InvoiceService::class);
        $invoiceService->sendInvoice($invoice->id);

        // Record a payment
        $this->postJson('/api/payments/record', [
            'invoice_id' => $invoice->id,
            'amount' => 100.00,
            'payment_method' => 'bank_transfer'
        ]);

        $response = $this->postJson("/api/invoices/{$invoice->id}/send-payment-confirmation");

        $response->assertStatus(400) // Expected to fail without proper email config
            ->assertJsonPath('success', false);
    }
}
