<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PanelType;
use App\Models\Quotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedDemoBoqsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_rate_less_boqs_for_the_company()
    {
        $company = Company::factory()->create();
        Customer::factory()->create(['company_id' => $company->id]);
        PanelType::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        $this->artisan('demo:seed-boqs', ['--company-email' => $company->email, '--count' => 5])->assertExitCode(0);

        $boqs = Quotation::where('company_id', $company->id)->get();
        $this->assertCount(5, $boqs);
        $this->assertTrue($boqs->every(fn ($q) => $q->status === 'boq' && (float) $q->total_sqm > 0 && $q->items()->count() > 0));
    }
}
