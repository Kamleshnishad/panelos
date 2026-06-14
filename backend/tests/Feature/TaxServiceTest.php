<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TaxConfiguration;
use App\Models\TaxCalculation;
use App\Models\Invoice;
use App\Models\Dispatch;
use App\Models\User;
use App\Services\TaxService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
    protected $taxService;
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
        $this->taxService = app(TaxService::class);
        $this->invoiceService = app(InvoiceService::class);
    }

    public function test_get_tax_configuration()
    {
        TaxConfiguration::create([
            'company_id' => $this->company->id,
            'gst_number' => '27AABUT1234K1ZA',
            'tax_type' => 'exclusive',
            'default_tax_rate' => 18,
            'is_active' => true
        ]);

        $config = $this->taxService->getTaxConfiguration($this->company->id);

        $this->assertNotNull($config);
        $this->assertEquals(18, $config->default_tax_rate);
    }

    public function test_update_tax_configuration()
    {
        $config = $this->taxService->updateTaxConfiguration($this->company->id, [
            'gst_number' => '27AABUT1234K1ZA',
            'tax_type' => 'exclusive',
            'default_tax_rate' => 18,
            'is_active' => true
        ]);

        $this->assertDatabaseHas('tax_configurations', [
            'company_id' => $this->company->id,
            'default_tax_rate' => 18
        ]);
    }

    public function test_validate_gst_number()
    {
        $valid = $this->taxService->validateGSTNumber('27AABUT1234K1ZA');
        $this->assertTrue($valid);
    }

    public function test_invalid_gst_number_format()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid GST number format');

        $this->taxService->validateGSTNumber('INVALID123');
    }

    public function test_apply_tax_to_invoice_exclusive()
    {
        TaxConfiguration::create([
            'company_id' => $this->company->id,
            'gst_number' => '27AABUT1234K1ZA',
            'tax_type' => 'exclusive',
            'default_tax_rate' => 18,
            'is_active' => true
        ]);

        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $tax = TaxCalculation::where('invoice_id', $invoice->id)->first();

        $this->assertNotNull($tax);
        $this->assertGreaterThan(0, $tax->tax_amount);
    }

    public function test_apply_tax_to_invoice_inclusive()
    {
        TaxConfiguration::create([
            'company_id' => $this->company->id,
            'gst_number' => '27AABUT1234K1ZA',
            'tax_type' => 'inclusive',
            'default_tax_rate' => 18,
            'is_active' => true
        ]);

        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $tax = TaxCalculation::where('invoice_id', $invoice->id)->first();

        $this->assertNotNull($tax);
        $this->assertGreaterThan(0, $tax->tax_amount);
    }

    public function test_calculate_tax_breakdown_gst()
    {
        $config = TaxConfiguration::create([
            'company_id' => $this->company->id,
            'gst_number' => '27AABUT1234K1ZA',
            'tax_type' => 'exclusive',
            'default_tax_rate' => 18,
            'is_active' => true
        ]);

        $breakdown = $this->taxService->calculateTaxBreakdown($config, 1000, 18);

        $this->assertArrayHasKey('sgst', $breakdown);
        $this->assertArrayHasKey('cgst', $breakdown);
        $this->assertArrayHasKey('igst', $breakdown);
        $this->assertEquals(0, $breakdown['igst']);
    }

    public function test_get_tax_report()
    {
        TaxConfiguration::create([
            'company_id' => $this->company->id,
            'gst_number' => '27AABUT1234K1ZA',
            'tax_type' => 'exclusive',
            'default_tax_rate' => 18,
            'is_active' => true
        ]);

        $dispatch = Dispatch::factory()->for($this->company)->create();
        $this->invoiceService->createFromDispatch($dispatch->id);

        $report = $this->taxService->getTaxReport($this->company->id);

        $this->assertArrayHasKey('total_tax', $report);
        $this->assertArrayHasKey('total_sgst', $report);
        $this->assertArrayHasKey('total_cgst', $report);
        $this->assertArrayHasKey('total_igst', $report);
    }
}
