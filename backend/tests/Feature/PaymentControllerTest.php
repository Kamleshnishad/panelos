<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dispatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
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

    protected function createSentInvoice()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $response = $this->postJson('/api/invoices/from-dispatch', ['dispatch_id' => $dispatch->id]);
        $invoice = $this->company->invoices()->first();
        $this->postJson("/api/invoices/{$invoice->id}/send");
        return $invoice;
    }

    public function test_record_payment()
    {
        $invoice = $this->createSentInvoice();

        $response = $this->postJson('/api/payments/record', [
            'invoice_id' => $invoice->id,
            'amount' => 100.00,
            'payment_method' => 'bank_transfer'
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', 100.00);
    }

    public function test_get_payment_history()
    {
        $invoice = $this->createSentInvoice();
        $this->postJson('/api/payments/record', [
            'invoice_id' => $invoice->id,
            'amount' => 50.00
        ]);

        $response = $this->getJson("/api/invoices/{$invoice->id}/payments");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_get_payment_status()
    {
        $invoice = $this->createSentInvoice();

        $response = $this->getJson("/api/invoices/{$invoice->id}/payment-status");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'invoice_id',
                    'total_amount',
                    'paid_amount',
                    'remaining_due',
                    'payment_percentage',
                    'status'
                ]
            ]);
    }

    public function test_issue_payment_reminder()
    {
        $invoice = $this->createSentInvoice();

        $response = $this->postJson("/api/invoices/{$invoice->id}/payment-reminder");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.message', 'Payment reminder issued');
    }

    public function test_write_off_payment()
    {
        $invoice = $this->createSentInvoice();

        $response = $this->postJson("/api/invoices/{$invoice->id}/write-off", [
            'amount' => 50.00,
            'reason' => 'bad_debt'
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_reconcile_payment()
    {
        $invoice = $this->createSentInvoice();

        $response = $this->postJson('/api/payments/reconcile', [
            'invoice_id' => $invoice->id,
            'paid_amount' => 100.00,
            'reference_no' => 'REF-001'
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_get_unpaid_invoices()
    {
        $this->createSentInvoice();

        $response = $this->getJson('/api/payments/unpaid');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}
