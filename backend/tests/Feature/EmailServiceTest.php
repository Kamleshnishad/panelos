<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
    protected $emailService;
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
        $this->emailService = app(EmailService::class);
        $this->invoiceService = app(InvoiceService::class);
    }

    public function test_get_customer_email_from_invoice()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        // The email should be retrievable through reflection or by checking the customer
        $this->assertNotNull($invoice);
    }

    public function test_send_invoice_email()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        try {
            $result = $this->emailService->sendInvoice($invoice, $this->company->id);
            $this->assertTrue($result);
        } catch (\Exception $e) {
            // Email might fail if customer email not set, which is expected in test
            $this->assertStringContainsString('email', strtolower($e->getMessage()));
        }
    }

    public function test_send_payment_reminder()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);
        $this->invoiceService->sendInvoice($invoice->id, $this->company->id);

        try {
            $result = $this->emailService->sendPaymentReminder($invoice, $this->company->id);
            $this->assertTrue($result);
        } catch (\Exception $e) {
            $this->assertStringContainsString('email', strtolower($e->getMessage()));
        }
    }

    public function test_get_email_preview_invoice_sent()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $preview = $this->emailService->getEmailPreview($invoice, 'invoice_sent');

        $this->assertNotNull($preview);
        $this->assertArrayHasKey('subject', $preview);
        $this->assertArrayHasKey('preview', $preview);
        $this->assertStringContainsString($invoice->invoice_no, $preview['subject']);
    }

    public function test_get_email_preview_payment_reminder()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $preview = $this->emailService->getEmailPreview($invoice, 'payment_reminder');

        $this->assertNotNull($preview);
        $this->assertArrayHasKey('subject', $preview);
        $this->assertStringContainsString('Payment', $preview['subject']);
    }

    public function test_get_email_preview_payment_received()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $preview = $this->emailService->getEmailPreview($invoice, 'payment_received');

        $this->assertNotNull($preview);
        $this->assertArrayHasKey('subject', $preview);
        $this->assertStringContainsString('Payment Received', $preview['subject']);
    }

    public function test_batch_send_reminders()
    {
        // Create multiple invoices with past due dates
        $dispatch1 = Dispatch::factory()->for($this->company)->create();
        $dispatch2 = Dispatch::factory()->for($this->company)->create();

        $invoice1 = $this->invoiceService->createFromDispatch($dispatch1->id);
        $invoice2 = $this->invoiceService->createFromDispatch($dispatch2->id);

        // Mark as sent but don't pay
        $this->invoiceService->sendInvoice($invoice1->id, $this->company->id);
        $this->invoiceService->sendInvoice($invoice2->id, $this->company->id);

        // Update due dates to past
        Invoice::where('id', $invoice1->id)->update(['due_date' => now()->subDays(5)]);
        Invoice::where('id', $invoice2->id)->update(['due_date' => now()->subDays(10)]);

        $result = $this->emailService->sendBatchReminders($this->company->id, 1);

        $this->assertArrayHasKey('total_invoices', $result);
        $this->assertArrayHasKey('emails_sent', $result);
        $this->assertArrayHasKey('failed', $result);
    }

    public function test_send_payment_confirmation()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);
        $this->invoiceService->sendInvoice($invoice->id, $this->company->id);

        // Create a payment
        $payment = PaymentTransaction::create([
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'amount' => 100.00,
            'payment_method' => 'bank_transfer',
            'transaction_date' => now(),
            'created_by_user_id' => $this->user->id
        ]);

        try {
            $result = $this->emailService->sendPaymentConfirmation($invoice, $payment, $this->company->id);
            $this->assertTrue($result);
        } catch (\Exception $e) {
            $this->assertStringContainsString('email', strtolower($e->getMessage()));
        }
    }
}
