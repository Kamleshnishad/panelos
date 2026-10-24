<?php

namespace Tests\Feature;

use App\Models\ChemicalStock;
use App\Models\Invoice;
use App\Services\EmailService;
use App\Services\ReportingService;
use App\Services\StockDashboardService;
use Tests\TestCase;

/**
 * The Carbon 3 date-difference bug (see DaysTest) showed up in customer-facing output: an overdue invoice got the
 * subject "Payment Due Soon", and the management aging report put every overdue invoice in the first bucket.
 */
class OverdueWordingTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function invoice($admin, int $dueInDays): Invoice
    {
        return Invoice::create([
            'company_id' => $admin->company_id, 'status' => 'sent', 'subtotal' => 1000, 'tax_amount' => 0, 'total_amount' => 1000,
            'invoice_date' => now()->subDays(60), 'due_date' => now()->addDays($dueInDays),
        ]);
    }

    public function test_a_reminder_for_an_overdue_invoice_says_how_many_days_overdue(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $invoice = $this->invoice($admin, -12);

        $preview = app(EmailService::class)->getEmailPreview($invoice, 'payment_reminder');

        $this->assertStringContainsString('12 days overdue', $preview['subject']);
        $this->assertStringNotContainsString('Due Soon', $preview['subject']);
    }

    public function test_a_reminder_for_an_invoice_not_yet_due_says_due_soon(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $invoice = $this->invoice($admin, 4);

        $preview = app(EmailService::class)->getEmailPreview($invoice, 'payment_reminder');

        $this->assertStringContainsString('Due Soon', $preview['subject']);
    }

    public function test_the_mis_aging_puts_each_invoice_in_the_bucket_for_its_age(): void
    {
        $admin = $this->createAdminUser();
        $this->invoice($admin, -10);    // 0-30
        $this->invoice($admin, -45);    // 31-60
        $this->invoice($admin, -75);    // 61-90
        $this->invoice($admin, -120);   // over 90

        $aging = app(ReportingService::class)->getMisReport($admin->company_id, now()->subYear()->toDateString(), now()->toDateString())['aging'];

        $this->assertEquals(['0_30' => 1000, '31_60' => 1000, '61_90' => 1000, 'over_90' => 1000], $aging,
            'Every overdue invoice used to land in the first bucket.');
    }

    public function test_the_expiry_list_counts_days_remaining_as_a_positive_number(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        ChemicalStock::create(['company_id' => $admin->company_id, 'name' => 'Polyol', 'category' => 'polyol', 'quantity_in_stock' => 50,
            'unit' => 'kg', 'reorder_level' => 0, 'expiry_date' => now()->addDays(10)]);

        $items = app(StockDashboardService::class)->getExpiringChemicals(30, $admin->company_id);

        $this->assertSame(10, $items[0]['days_remaining']);
    }
}
