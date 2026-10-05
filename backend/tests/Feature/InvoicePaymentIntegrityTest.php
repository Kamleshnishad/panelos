<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use Tests\TestCase;

class InvoicePaymentIntegrityTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function sentInvoice(int $companyId, float $subtotal = 1000): Invoice
    {
        return Invoice::create([
            'company_id' => $companyId,
            'invoice_no' => 'INV-TEST-' . uniqid(),
            'status' => 'sent',
            'subtotal' => $subtotal,
            'tax_amount' => 0,
            'total_amount' => $subtotal,
            'invoice_date' => now(),
            'due_date' => now()->addDays(10),
        ]);
    }

    public function test_overpayment_is_rejected(): void
    {
        $admin = $this->createAdminUser();
        $invoice = $this->sentInvoice($admin->company_id, 1000);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/payments/record', ['invoice_id' => $invoice->id, 'amount' => 1500])
            ->assertStatus(400);

        $this->assertSame(0, PaymentTransaction::where('invoice_id', $invoice->id)->count());
    }

    public function test_full_payment_marks_invoice_paid(): void
    {
        $admin = $this->createAdminUser();
        $invoice = $this->sentInvoice($admin->company_id, 1000);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/payments/record', ['invoice_id' => $invoice->id, 'amount' => 1000])
            ->assertStatus(201);

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_mark_paid_is_blocked_while_balance_remains(): void
    {
        $admin = $this->createAdminUser();
        $invoice = $this->sentInvoice($admin->company_id, 1000);
        PaymentTransaction::create([
            'company_id' => $admin->company_id,
            'invoice_id' => $invoice->id,
            'amount' => 200,
            'payment_method' => 'cash',
            'transaction_date' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/invoices/{$invoice->id}/mark-paid")
            ->assertStatus(400);

        $this->assertSame('sent', $invoice->fresh()->status);
    }

    public function test_mark_paid_succeeds_when_balance_is_zero(): void
    {
        $admin = $this->createAdminUser();
        $invoice = $this->sentInvoice($admin->company_id, 1000);
        PaymentTransaction::create([
            'company_id' => $admin->company_id,
            'invoice_id' => $invoice->id,
            'amount' => 1000,
            'payment_method' => 'cash',
            'transaction_date' => now(),
        ]);

        // Recording the full amount already flips status to paid, so reset to
        // 'sent' to exercise the manual path with zero balance.
        $invoice->update(['status' => 'sent']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/invoices/{$invoice->id}/mark-paid")
            ->assertStatus(200);

        $this->assertSame('paid', $invoice->fresh()->status);
    }
}
