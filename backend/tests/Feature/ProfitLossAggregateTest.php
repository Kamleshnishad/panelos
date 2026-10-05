<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\TaxCalculation;
use App\Services\ReportingService;
use Tests\TestCase;

class ProfitLossAggregateTest extends TestCase
{
    public function test_pnl_totals_match_subtotal_and_tax_sums(): void
    {
        $company = Company::factory()->create();
        $invoice = Invoice::create([
            'company_id' => $company->id, 'invoice_no' => 'INV-PL-' . uniqid(), 'status' => 'sent',
            'subtotal' => 1000, 'tax_amount' => 180, 'total_amount' => 1180,
            'invoice_date' => now(), 'due_date' => now()->addDays(10),
        ]);
        // Inserted directly: TaxCalculation disables timestamps and doesn't fill created_at.
        \Illuminate\Support\Facades\DB::table('tax_calculations')->insert([
            'invoice_id' => $invoice->id, 'tax_rate' => 18, 'taxable_amount' => 1000, 'tax_amount' => 180,
            'created_at' => now(),
        ]);

        $pnl = app(ReportingService::class)->getProfitLossStatement(
            $company->id, now()->subDay()->toDateString(), now()->addDay()->toDateString()
        );

        $this->assertEquals(1000, $pnl['revenue']['sales']);
        $this->assertEquals(180, $pnl['revenue']['tax_collected']);
        $this->assertEquals(1180, $pnl['revenue']['gross_revenue']);
        $this->assertEquals(1, $pnl['invoice_count']);
    }
}
