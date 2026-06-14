<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TaxConfiguration;
use App\Models\Dispatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;

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

        TaxConfiguration::create([
            'company_id' => $this->company->id,
            'gst_number' => '27AABUT1234K1ZA',
            'tax_type' => 'exclusive',
            'default_tax_rate' => 18,
            'is_active' => true
        ]);
    }

    protected function createSentInvoice()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $response = $this->postJson('/api/invoices/from-dispatch', ['dispatch_id' => $dispatch->id]);
        $invoice = $this->company->invoices()->first();
        $this->postJson("/api/invoices/{$invoice->id}/send");
        return $invoice;
    }

    public function test_get_profit_loss_statement()
    {
        $this->createSentInvoice();

        $response = $this->getJson('/api/reports/profit-loss');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'period',
                    'revenue',
                    'invoice_count'
                ]
            ]);
    }

    public function test_get_balance_sheet()
    {
        $this->createSentInvoice();

        $response = $this->getJson('/api/reports/balance-sheet');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'as_of_date',
                    'assets',
                    'liabilities',
                    'equity'
                ]
            ]);
    }

    public function test_get_cash_flow_statement()
    {
        $this->createSentInvoice();

        $response = $this->getJson('/api/reports/cash-flow');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'period',
                    'operating_activities'
                ]
            ]);
    }

    public function test_get_accounts_receivable_aging()
    {
        $this->createSentInvoice();

        $response = $this->getJson('/api/reports/accounts-receivable');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'summary',
                    'details'
                ]
            ]);
    }

    public function test_get_sales_report()
    {
        $this->createSentInvoice();

        $response = $this->getJson('/api/reports/sales');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'period',
                    'summary'
                ]
            ]);
    }

    public function test_get_tax_report()
    {
        $this->createSentInvoice();

        $response = $this->getJson('/api/reports/tax');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data'
            ]);
    }

    public function test_get_accounting_dashboard()
    {
        $this->createSentInvoice();

        $response = $this->getJson('/api/reports/accounting-dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'summary',
                    'pl_statement',
                    'balance_sheet'
                ]
            ]);
    }

    public function test_reconcile_invoices()
    {
        $this->createSentInvoice();

        $response = $this->getJson('/api/reports/reconcile');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_invoiced',
                    'total_paid',
                    'total_outstanding'
                ]
            ]);
    }
}
