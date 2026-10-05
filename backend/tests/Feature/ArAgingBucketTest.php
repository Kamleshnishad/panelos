<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\ReportingService;
use Tests\TestCase;

class ArAgingBucketTest extends TestCase
{
    public function test_overdue_invoice_lands_in_the_correct_bucket(): void
    {
        $company = Company::factory()->create();
        Invoice::create([
            'company_id' => $company->id, 'invoice_no' => 'INV-AR-' . uniqid(), 'status' => 'sent',
            'subtotal' => 500, 'tax_amount' => 0, 'total_amount' => 500,
            'invoice_date' => now()->subDays(80), 'due_date' => now()->subDays(45),
        ]);

        $ar = app(ReportingService::class)->getAccountsReceivable($company->id);

        $this->assertEquals(500, $ar['summary']['total_ar']);
        $this->assertEquals(500, $ar['summary']['60_days']); // 45 days overdue → 31–60 bucket
        $this->assertEquals(0, $ar['summary']['current']);
        $this->assertEquals(1, $ar['summary']['overdue_count']);
    }
}
