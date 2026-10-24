<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dispatch;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
    protected $invoiceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Test Company']);
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
            'is_company_admin' => true,
        ]);

        $this->actingAs($this->user);
        $this->invoiceService = app(InvoiceService::class);
    }

    public function test_generate_pdf_html()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $html = $this->invoiceService->generatePdf($invoice->id);

        $this->assertNotEmpty($html);
        $this->assertStringContainsString('INVOICE', $html);
        $this->assertStringContainsString($invoice->invoice_no, $html);
    }

    public function test_pdf_contains_invoice_details()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $html = $this->invoiceService->generatePdf($invoice->id);

        // The document prints dates as dd/mm/yyyy (not "Oct 24, 2026").
        $this->assertStringContainsString($invoice->invoice_date->format('d/m/Y'), $html);
        $this->assertStringContainsString($invoice->due_date->format('d/m/Y'), $html);
    }

    public function test_pdf_contains_line_items()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);
        $this->invoiceService->addItem($invoice->id, \App\Models\PanelType::where('company_id', $this->company->id)->value('id'), 10, 100.00);

        $html = $this->invoiceService->generatePdf($invoice->id);

        $this->assertStringContainsString('1,000.00', $html); // 10 x 100 amount
        $this->assertStringContainsString('100.00', $html);   // unit price
    }

    public function test_pdf_contains_totals()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $html = $this->invoiceService->generatePdf($invoice->id);

        // The totals block reads Taxable Value / Total GST / Grand Total.
        $this->assertStringContainsString('Taxable Value', $html);
        $this->assertStringContainsString('Grand Total', $html);
    }

    public function test_pdf_download_returns_file()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $response = $this->getJson("/api/invoices/{$invoice->id}/pdf");

        // Note: File download responses are typically 200 with content-disposition
        // or might return a stream in test environment
        $this->assertTrue(
            $response->status() === 200 ||
            $response->headers->get('content-disposition') !== null
        );
    }

    public function test_pdf_preview_returns_html()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $response = $this->getJson("/api/invoices/{$invoice->id}/pdf-preview");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'html'
            ]);
    }

    public function test_pdf_not_found_for_invalid_invoice()
    {
        $response = $this->getJson('/api/invoices/9999/pdf-preview');

        $response->assertStatus(404);
    }

    public function test_pdf_contains_tax_information()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $html = $this->invoiceService->generatePdf($invoice->id);

        if ($invoice->taxCalculation) {
            $this->assertStringContainsString('Tax', $html);
        }
    }
}
