<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TaxConfiguration;
use App\Models\Invoice;
use App\Models\Dispatch;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\ReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
    protected $reportingService;
    protected $invoiceService;
    protected $paymentService;

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
        $this->reportingService = app(ReportingService::class);
        $this->invoiceService = app(InvoiceService::class);
        $this->paymentService = app(PaymentService::class);

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
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);
        $this->invoiceService->sendInvoice($invoice->id);
        return $invoice;
    }

    public function test_get_profit_loss_statement()
    {
        $this->createSentInvoice();

        $statement = $this->reportingService->getProfitLossStatement($this->company->id);

        $this->assertArrayHasKey('revenue', $statement);
        $this->assertArrayHasKey('period', $statement);
        $this->assertArrayHasKey('invoice_count', $statement);
    }

    public function test_get_balance_sheet()
    {
        $invoice = $this->createSentInvoice();

        $sheet = $this->reportingService->getBalanceSheet($this->company->id);

        $this->assertArrayHasKey('assets', $sheet);
        $this->assertArrayHasKey('liabilities', $sheet);
        $this->assertArrayHasKey('equity', $sheet);
    }

    public function test_get_cash_flow_statement()
    {
        $invoice = $this->createSentInvoice();
        $total = $invoice->subtotal + ($invoice->taxCalculation->tax_amount ?? 0);
        $this->paymentService->recordPayment($invoice->id, $total);

        $statement = $this->reportingService->getCashFlowStatement($this->company->id);

        $this->assertArrayHasKey('operating_activities', $statement);
        $this->assertArrayHasKey('invoice_count', $statement);
    }

    public function test_get_accounts_receivable_aging()
    {
        $this->createSentInvoice();
        $this->createSentInvoice();

        $ar = $this->reportingService->getAccountsReceivable($this->company->id);

        $this->assertArrayHasKey('summary', $ar);
        $this->assertArrayHasKey('details', $ar);
        $this->assertArrayHasKey('total_ar', $ar['summary']);
    }

    public function test_get_sales_report()
    {
        $this->createSentInvoice();

        $report = $this->reportingService->getSalesReport($this->company->id);

        $this->assertArrayHasKey('period', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('by_invoice', $report);
    }

    public function test_get_tax_report()
    {
        $this->createSentInvoice();

        $report = $this->reportingService->getTaxReport($this->company->id);

        $this->assertArrayHasKey('total_tax', $report);
        $this->assertArrayHasKey('total_taxable', $report);
    }

    public function test_get_accounting_dashboard()
    {
        $this->createSentInvoice();

        $dashboard = $this->reportingService->getAccountingDashboard($this->company->id);

        $this->assertArrayHasKey('summary', $dashboard);
        $this->assertArrayHasKey('pl_statement', $dashboard);
        $this->assertArrayHasKey('balance_sheet', $dashboard);
        $this->assertArrayHasKey('recent_invoices', $dashboard);
    }

    public function test_reconcile_invoices()
    {
        $invoice = $this->createSentInvoice();

        $reconciliation = $this->reportingService->reconcileInvoices($this->company->id);

        $this->assertArrayHasKey('total_invoiced', $reconciliation);
        $this->assertArrayHasKey('total_paid', $reconciliation);
        $this->assertArrayHasKey('total_outstanding', $reconciliation);
        $this->assertArrayHasKey('invoices_reconciled', $reconciliation);
    }
}
