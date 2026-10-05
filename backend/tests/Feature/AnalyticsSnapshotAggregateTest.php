<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\AnalyticsService;
use Tests\TestCase;

class AnalyticsSnapshotAggregateTest extends TestCase
{
    public function test_snapshot_totals_come_from_sql_aggregation(): void
    {
        $company = Company::factory()->create();
        foreach ([1000, 500] as $subtotal) {
            Invoice::create([
                'company_id' => $company->id, 'invoice_no' => 'INV-SN-' . uniqid(), 'status' => 'sent',
                'subtotal' => $subtotal, 'tax_amount' => 0, 'total_amount' => $subtotal,
                'invoice_date' => now(), 'due_date' => now()->addDays(10),
            ]);
        }
        // Draft invoices must not count toward snapshot totals.
        Invoice::create([
            'company_id' => $company->id, 'invoice_no' => 'INV-SN-D-' . uniqid(), 'status' => 'draft',
            'subtotal' => 9999, 'tax_amount' => 0, 'total_amount' => 9999,
            'invoice_date' => now(), 'due_date' => now()->addDays(10),
        ]);

        $snapshot = app(AnalyticsService::class)->createAnalyticsSnapshot($company->id);

        $this->assertEquals(2, $snapshot->total_invoices);
        $this->assertEquals(1500, (float) $snapshot->total_revenue);
        $this->assertEquals(750, (float) $snapshot->average_invoice_value);
    }
}
