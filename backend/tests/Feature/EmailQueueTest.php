<?php

namespace Tests\Feature;

use App\Mail\PaymentReminderMailable;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\EmailService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailQueueTest extends TestCase
{
    public function test_payment_reminder_is_queued_not_sent_inline(): void
    {
        Mail::fake();

        $company = Company::factory()->create();
        $customer = Customer::factory()->create(['company_id' => $company->id, 'email' => 'buyer@example.com']);
        $quotation = \App\Models\Quotation::create([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'quotation_no' => 'Q-EQ-' . uniqid(), 'status' => 'accepted',
        ]);
        $order = \App\Models\Order::create([
            'company_id' => $company->id, 'quotation_id' => $quotation->id, 'customer_id' => $customer->id,
            'order_no' => 'ORD-EQ-' . uniqid(), 'order_date' => now(), 'status' => 'completed',
        ]);
        $batch = \App\Models\ProductionBatch::create([
            'company_id' => $company->id, 'order_id' => $order->id, 'batch_no' => 'PB-EQ-' . uniqid(),
            'status' => 'dispatched', 'planned_quantity' => 1, 'completed_quantity' => 1,
        ]);
        $dispatch = \App\Models\Dispatch::create([
            'company_id' => $company->id, 'batch_id' => $batch->id, 'status' => 'delivered',
        ]);
        $invoice = Invoice::create([
            'company_id' => $company->id, 'dispatch_id' => $dispatch->id, 'invoice_no' => 'INV-Q-' . uniqid(),
            'status' => 'sent', 'subtotal' => 500, 'tax_amount' => 0, 'total_amount' => 500,
            'invoice_date' => now()->subDays(30), 'due_date' => now()->subDays(20),
        ]);

        app(EmailService::class)->sendPaymentReminder($invoice, $company->id);

        Mail::assertQueued(PaymentReminderMailable::class);
        Mail::assertNotSent(PaymentReminderMailable::class);
    }
}
