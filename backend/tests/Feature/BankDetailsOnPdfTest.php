<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PanelType;
use App\Models\Quotation;
use App\Services\InvoiceService;
use Tests\TestCase;

/**
 * The quotation PDF read company->bank_account and ->ifsc_code, and the invoice PDF read ->bank_account.
 * None of those columns exist (they are bank_account_no and bank_ifsc), so the account number and IFSC
 * always printed as a dash — even after the bank details had been saved in Settings → Company.
 */
class BankDetailsOnPdfTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function companyWithBank()
    {
        $admin = $this->createAdminUser();
        $admin->company->update(['bank_name' => 'HDFC Bank Ltd', 'bank_account_no' => '59200000008899', 'bank_ifsc' => 'HDFC0003001']);
        return $admin;
    }

    public function test_the_classic_quotation_prints_the_saved_account_number_and_ifsc(): void
    {
        $admin = $this->companyWithBank();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $pt = PanelType::factory()->create(['company_id' => $admin->company_id]);
        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'panel_rows' => [[
                'panel_type_id' => $pt->id, 'thickness' => 50, 'density_type' => 'PUF', 'density_kgm3' => 40,
                'top_skin_material' => 'PPGI', 'top_skin_thickness' => '0.40', 'top_surface' => 'PLAIN',
                'bottom_skin_material' => 'PPGI', 'bottom_skin_thickness' => '0.40',
                'sizes' => [['length_mm' => 3000, 'nos' => 2, 'rate_per_sqm' => 500]],
            ]],
        ])->assertStatus(201);

        $html = view('quotations.pdf', ['quotation' => Quotation::find($res->json('data.id'))])->render();

        $this->assertStringContainsString('Account No: 59200000008899', $html);
        $this->assertStringContainsString('IFSC Code: HDFC0003001', $html);
        $this->assertStringNotContainsString('Account No: —', $html);
    }

    public function test_the_classic_quotation_still_shows_a_dash_when_nothing_is_saved(): void
    {
        $admin = $this->createAdminUser();
        $admin->company->update(['bank_account_no' => '', 'bank_ifsc' => '']);   // the factory fills fake ones
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $quotation = Quotation::create(['company_id' => $admin->company_id, 'customer_id' => $customer->id,
            'quotation_no' => 'Q-BK-' . uniqid(), 'status' => 'draft']);

        $html = view('quotations.pdf', ['quotation' => $quotation->load('company', 'customer')])->render();

        $this->assertStringContainsString('Account No: —', $html);
        $this->assertStringContainsString('IFSC Code: —', $html);
    }

    public function test_the_invoice_prints_the_saved_account_number(): void
    {
        $admin = $this->companyWithBank();
        $invoice = Invoice::create([
            'company_id' => $admin->company_id, 'invoice_no' => 'INV-BK-' . uniqid(), 'status' => 'sent',
            'subtotal' => 500, 'tax_amount' => 0, 'total_amount' => 500,
            'invoice_date' => now()->subDays(2), 'due_date' => now()->addDays(5),
        ]);

        $html = app(InvoiceService::class)->generatePdf($invoice->id, $admin->company_id);

        $this->assertStringContainsString('A/c: 59200000008899', $html);
        $this->assertStringContainsString('IFSC: HDFC0003001', $html);
    }
}
