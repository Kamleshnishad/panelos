<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\Dispatch;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
    protected $paymentService;
    protected $invoiceService;

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
        $this->paymentService = app(PaymentService::class);
        $this->invoiceService = app(InvoiceService::class);
    }

    protected function createSentInvoice()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);
        $this->invoiceService->sendInvoice($invoice->id);
        return $invoice;
    }

    public function test_record_payment()
    {
        $invoice = $this->createSentInvoice();

        $payment = $this->paymentService->recordPayment(
            $invoice->id,
            100.00,
            'bank_transfer'
        );

        $this->assertDatabaseHas('payment_transactions', [
            'invoice_id' => $invoice->id,
            'amount' => 100.00,
            'payment_method' => 'bank_transfer'
        ]);

        $this->assertEquals(100.00, $payment->amount);
    }

    public function test_cannot_record_payment_on_draft_invoice()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot record payment for draft invoice');

        $this->paymentService->recordPayment($invoice->id, 100.00);
    }

    public function test_calculate_remaining_due()
    {
        $invoice = $this->createSentInvoice();

        $this->paymentService->recordPayment($invoice->id, 50.00);

        $remaining = $this->paymentService->calculateRemainingDue($invoice->id);

        $this->assertGreaterThan(0, $remaining);
    }

    public function test_get_payment_history()
    {
        $invoice = $this->createSentInvoice();
        $this->paymentService->recordPayment($invoice->id, 50.00);
        $this->paymentService->recordPayment($invoice->id, 50.00);

        $history = $this->paymentService->getPaymentHistory($invoice->id);

        $this->assertEquals(2, $history->count());
    }

    public function test_get_payment_status()
    {
        $invoice = $this->createSentInvoice();
        $this->paymentService->recordPayment($invoice->id, 50.00);

        $status = $this->paymentService->getPaymentStatus($invoice->id);

        $this->assertArrayHasKey('total_amount', $status);
        $this->assertArrayHasKey('paid_amount', $status);
        $this->assertArrayHasKey('remaining_due', $status);
        $this->assertArrayHasKey('payment_percentage', $status);
    }

    public function test_issue_reminder()
    {
        $invoice = $this->createSentInvoice();

        $reminder = $this->paymentService->issueReminder($invoice->id);

        $this->assertArrayHasKey('remaining_due', $reminder);
        $this->assertArrayHasKey('reminder_sent_at', $reminder);
    }

    public function test_cannot_issue_reminder_on_paid_invoice()
    {
        $invoice = $this->createSentInvoice();
        $total = $invoice->subtotal + ($invoice->taxCalculation->tax_amount ?? 0);
        $this->paymentService->recordPayment($invoice->id, $total);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('already fully paid');

        $this->paymentService->issueReminder($invoice->id);
    }

    public function test_write_off()
    {
        $invoice = $this->createSentInvoice();

        $result = $this->paymentService->writeOff($invoice->id, 50.00, 'bad_debt');

        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayHasKey('remaining_after_writeoff', $result);
    }

    public function test_reconcile_payment()
    {
        $invoice = $this->createSentInvoice();

        $status = $this->paymentService->reconcilePayment(
            $invoice->id,
            50.00,
            'REF-001'
        );

        $this->assertArrayHasKey('payment_percentage', $status);
    }

    public function test_get_unpaid_invoices()
    {
        $invoice1 = $this->createSentInvoice();
        $invoice2 = $this->createSentInvoice();

        $unpaid = $this->paymentService->getUnpaidInvoices($this->company->id);

        $this->assertGreaterThanOrEqual(2, $unpaid->count());
    }
}
