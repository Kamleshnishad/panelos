<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\User;
use App\Models\GstConfiguration;
use App\Services\GstService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GstMultiStateTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
    protected $dispatch;
    protected $invoice;
    protected $gstService;

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

        $this->dispatch = Dispatch::factory()->for($this->company)->create();
        $this->postJson('/api/invoices/from-dispatch', [
            'dispatch_id' => $this->dispatch->id
        ]);

        $this->invoice = $this->company->invoices()->first();
        $this->gstService = new GstService();
    }

    public function test_register_gst_configuration()
    {
        $response = $this->postJson('/api/gst/register', [
            'state_code' => 'MH',
            'gstin' => '27AABCT1234H1Z0',
            'registration_type' => 'regular'
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'company_id',
                    'state_code',
                    'state_name',
                    'gstin',
                    'registration_type',
                ]
            ]);

        $this->assertDatabaseHas('gst_configurations', [
            'company_id' => $this->company->id,
            'state_code' => 'MH',
            'gstin' => '27AABCT1234H1Z0'
        ]);
    }

    public function test_register_multiple_gst_configurations()
    {
        $this->postJson('/api/gst/register', [
            'state_code' => 'MH',
            'gstin' => '27AABCT1234H1Z0',
            'registration_type' => 'regular'
        ]);

        $response = $this->postJson('/api/gst/register', [
            'state_code' => 'KA',
            'gstin' => '29AABCT5678H2Z0',
            'registration_type' => 'regular'
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('gst_configurations', [
            'company_id' => $this->company->id,
            'state_code' => 'MH',
            'is_primary' => true
        ]);

        $this->assertDatabaseHas('gst_configurations', [
            'company_id' => $this->company->id,
            'state_code' => 'KA',
            'is_primary' => false
        ]);
    }

    public function test_add_hsn_code()
    {
        $response = $this->postJson('/api/gst/hsn-code', [
            'code' => '7308',
            'description' => 'Structural Steel',
            'category' => 'Steel Products',
            'gst_rate' => 5,
            'cess_rate' => 0
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'company_id',
                    'code',
                    'description',
                    'gst_rate',
                ]
            ]);
    }

    public function test_add_hsn_code_invalid_rate()
    {
        $response = $this->postJson('/api/gst/hsn-code', [
            'code' => '7308',
            'description' => 'Structural Steel',
            'category' => 'Steel Products',
            'gst_rate' => 10, // Invalid rate
            'cess_rate' => 0
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_calculate_gst_intra_state()
    {
        // Register GST for Maharashtra
        $this->gstService->registerGstConfiguration(
            $this->company->id,
            'MH',
            '27AABCT1234H1Z0',
            'regular'
        );

        $result = $this->gstService->calculateGst($this->invoice, $this->company->id, 18);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['is_intra_state']);
        $this->assertEquals(18, $result['tax_rate']);

        // Verify SGST and CGST are split equally
        $this->assertEquals($result['sgst_amount'], $result['cgst_amount']);
        $this->assertEquals(0, $result['igst_amount']);
    }

    public function test_get_gst_configurations()
    {
        $this->postJson('/api/gst/register', [
            'state_code' => 'MH',
            'gstin' => '27AABCT1234H1Z0',
            'registration_type' => 'regular'
        ]);

        $response = $this->getJson('/api/gst/configurations');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'state_code',
                        'gstin',
                    ]
                ]
            ]);
    }

    public function test_generate_gst_report()
    {
        $this->gstService->registerGstConfiguration(
            $this->company->id,
            'MH',
            '27AABCT1234H1Z0',
            'regular'
        );

        $this->gstService->calculateGst($this->invoice, $this->company->id, 18);

        $response = $this->getJson('/api/gst/report');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_invoices',
                    'total_sgst',
                    'total_cgst',
                    'total_tax',
                ]
            ]);
    }

    public function test_get_gst_compliance()
    {
        $this->gstService->registerGstConfiguration(
            $this->company->id,
            'MH',
            '27AABCT1234H1Z0',
            'regular'
        );

        $this->gstService->calculateGst($this->invoice, $this->company->id, 18);

        $response = $this->getJson('/api/gst/compliance');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'sgst_payable',
                    'cgst_payable',
                    'igst_payable',
                    'total_gst_payable',
                ]
            ]);
    }

    public function test_validate_gstin_valid()
    {
        $response = $this->postJson('/api/gst/validate-gstin', [
            'gstin' => '27AABCT1234H1Z0',
            'state_code' => 'MH'
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_validate_gstin_invalid_format()
    {
        $response = $this->postJson('/api/gst/validate-gstin', [
            'gstin' => 'INVALID1234'
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_get_states_list()
    {
        $response = $this->getJson('/api/gst/states');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'MH',
                    'KA',
                    'DL',
                ]
            ]);

        $this->assertCount(36, $response['data']);
    }

    public function test_gst_rates_validation()
    {
        $validRates = [0, 5, 12, 18, 28];

        foreach ($validRates as $rate) {
            $response = $this->postJson('/api/gst/hsn-code', [
                'code' => "HSN{$rate}",
                'description' => "Product at {$rate}%",
                'category' => 'Test',
                'gst_rate' => $rate,
            ]);

            $response->assertStatus(201);
        }
    }

    public function test_gst_tax_breakdown_persistence()
    {
        $this->gstService->registerGstConfiguration(
            $this->company->id,
            'MH',
            '27AABCT1234H1Z0',
            'regular'
        );

        $result = $this->gstService->calculateGst($this->invoice, $this->company->id, 18);

        $this->assertDatabaseHas('gst_tax_breakdowns', [
            'invoice_id' => $this->invoice->id,
            'company_id' => $this->company->id,
            'gst_rate' => 18,
        ]);

        $breakdown = $this->gstService->getGstBreakdown($this->invoice->id);
        $this->assertTrue($breakdown['success']);
    }

    public function test_calculate_gst_endpoint()
    {
        $this->gstService->registerGstConfiguration(
            $this->company->id,
            'MH',
            '27AABCT1234H1Z0',
            'regular'
        );

        $response = $this->postJson("/api/invoices/{$this->invoice->id}/calculate-gst", [
            'gst_rate' => 18
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'tax_rate',
                'tax_amount',
                'sgst_amount',
                'cgst_amount',
            ]);
    }

    public function test_get_gst_breakdown_endpoint()
    {
        $this->gstService->registerGstConfiguration(
            $this->company->id,
            'MH',
            '27AABCT1234H1Z0',
            'regular'
        );

        $this->gstService->calculateGst($this->invoice, $this->company->id, 18);

        $response = $this->getJson("/api/invoices/{$this->invoice->id}/gst-breakdown");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'gst_rate',
                    'sgst_amount',
                    'cgst_amount',
                    'igst_amount',
                ]
            ]);
    }
}
