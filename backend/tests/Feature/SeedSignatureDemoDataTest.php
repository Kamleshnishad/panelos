<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\PanelType;
use App\Models\ProductionBatch;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `php artisan demo:seed-signature` — reconstructs one real, verified
 * transaction (a Proforma Invoice + BOQ from Uma Signature Puf Panel LLP to
 * Kwality Business Associate LLP) end to end through the real service layer,
 * so every module has a realistic demo entry with internally-consistent
 * computed totals.
 */
class SeedSignatureDemoDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // QuotationService::generateQuotationNumber() uses MySQL's REGEXP,
        // unsupported on SQLite (the test driver) — a pre-existing,
        // documented, unrelated test-environment gap (see
        // QuotationAccessoryValidationTest). This command always creates a
        // brand-new quotation via create(), so there's no update()-based
        // workaround here; verified manually against the real MySQL dev DB
        // instead (all computed totals/statuses matched by hand).
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Needs MySQL for QuotationService::generateQuotationNumber()\'s REGEXP dependency.');
        }
    }

    public function test_seeds_the_full_chain_with_correct_inter_state_gst_and_lead_status(): void
    {
        $email = 'demo' . uniqid() . '@example.com';
        Artisan::call('tenant:provision', [
            '--company' => 'Test Signature Co',
            '--email' => $email,
            '--password' => 'Password123!',
            '--state' => 'Gujarat',
            '--state-code' => '24',
            '--plan' => 'enterprise',
        ]);

        $exitCode = Artisan::call('demo:seed-signature', ['--company-email' => $email]);
        $this->assertSame(0, $exitCode, Artisan::output());

        $company = Company::withoutGlobalScope('tenant')->where('email', $email)->first();

        $this->assertSame(8, PanelType::withoutGlobalScope('tenant')->where('company_id', $company->id)->count());

        $quotation = Quotation::withoutGlobalScope('tenant')->where('company_id', $company->id)->first();
        $this->assertSame('accepted', $quotation->status);
        // Gujarat seller (via the company's now-set GSTIN) + Rajasthan buyer
        // must compute as inter-state (IGST), not CGST+SGST.
        $this->assertTrue((bool) $quotation->is_inter_state);
        $this->assertGreaterThan(0, (float) $quotation->igst_amount);
        $this->assertSame(0.0, (float) $quotation->cgst_amount);
        $this->assertEqualsWithDelta(422235.0, (float) $quotation->total_amount, 1.0);

        $lead = Lead::withoutGlobalScope('tenant')->where('company_id', $company->id)->first();
        $this->assertSame('won', $lead->status);
        $this->assertSame($quotation->id, $lead->quotation_id);

        $batch = ProductionBatch::withoutGlobalScope('tenant')->where('company_id', $company->id)->first();
        $this->assertSame('dispatched', $batch->status);

        $dispatch = Dispatch::withoutGlobalScope('tenant')->where('company_id', $company->id)->first();
        $this->assertSame('delivered', $dispatch->status);

        $invoice = Invoice::withoutGlobalScope('tenant')->where('company_id', $company->id)->first();
        $this->assertSame('sent', $invoice->status);
        $this->assertDatabaseHas('payment_transactions', ['invoice_id' => $invoice->id, 'amount' => 150000]);

        $admin = User::withoutGlobalScope('tenant')->where('email', $email)->first();
        $this->assertNotNull($admin);
    }

    public function test_rerunning_does_not_duplicate_the_catalog_or_customer(): void
    {
        $email = 'demo' . uniqid() . '@example.com';
        Artisan::call('tenant:provision', [
            '--company' => 'Test Signature Co', '--email' => $email,
            '--password' => 'Password123!', '--plan' => 'enterprise',
        ]);

        Artisan::call('demo:seed-signature', ['--company-email' => $email]);
        $company = Company::withoutGlobalScope('tenant')->where('email', $email)->first();
        $panelCountBefore = PanelType::withoutGlobalScope('tenant')->where('company_id', $company->id)->count();

        // Second run will throw once it tries to re-create the SAME customer's
        // quotation/order chain (e.g. duplicate quotation number is fine, but
        // the catalog/customer/stage upserts must still be idempotent and not
        // duplicate rows before that point).
        try {
            Artisan::call('demo:seed-signature', ['--company-email' => $email]);
        } catch (\Throwable) {
            // Expected — the chain isn't idempotent past the catalog/customer
            // setup (a real second transaction isn't the point of this command).
        }

        $this->assertSame($panelCountBefore, PanelType::withoutGlobalScope('tenant')->where('company_id', $company->id)->count());
        $this->assertSame(1, \App\Models\Customer::withoutGlobalScope('tenant')->where('company_id', $company->id)->where('name', 'Kwality Business Associate LLP')->count());
    }
}
