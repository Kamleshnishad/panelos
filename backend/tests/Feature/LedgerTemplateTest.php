<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Customer;
use App\Models\PanelType;
use App\Models\Quotation;
use App\Services\DocumentTemplateService;
use App\Services\QuotationPdfService;
use Tests\TestCase;

/**
 * Phase 1 of QUOTATION_TEMPLATES_PLAN.md — the "Ledger" quotation template.
 * The fixture is the real UMA quotation (SCP-2027-012), so the numbers reconcile with the approved design:
 * 772,437.50 taxable, 139,038.75 GST, 911,476.00 grand total.
 */
class LedgerTemplateTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function panelRow(PanelType $pt, string $application, int $thickness, int $length, int $nos, float $rate): array
    {
        return ['panel_type_id' => $pt->id, 'application' => $application, 'thickness' => $thickness,
            'density_type' => 'PUF', 'density_kgm3' => 40,
            'top_skin_material' => 'PPGL', 'top_skin_thickness' => '0.50', 'top_color' => 'Off White', 'top_color_ral' => '9002', 'top_surface' => 'PLAIN',
            'bottom_skin_material' => 'PPGL', 'bottom_skin_thickness' => '0.50', 'bottom_color' => 'Off White', 'bottom_color_ral' => '9002', 'bottom_surface' => 'PLAIN',
            'guard_film' => true, 'cello_tap' => true, 'hsn_code' => '39259010',
            'sizes' => [['length_mm' => $length, 'nos' => $nos, 'rate_per_sqm' => $rate]]];
    }

    private function acc(int $company, string $name, string $unit, string $hsn): Accessory
    {
        return Accessory::factory()->create(['company_id' => $company, 'name' => $name, 'unit' => $unit, 'hsn_code' => $hsn, 'description' => $name]);
    }

    /** @return array{0:\App\Models\User,1:Quotation} */
    private function umaQuotation(array $over = [], array $customerOver = []): array
    {
        $admin = $this->createAdminUser();
        $cid = $admin->company_id;
        $admin->company->update(['name' => 'Signature PUF Panel', 'city' => 'Vadodara', 'state' => 'Gujarat', 'state_code' => '24',
            'gstin' => '24AAFFU9050M1ZS', 'pan' => 'AAFFU9050M', 'bank_name' => 'HDFC Bank Ltd', 'bank_branch' => 'Manjusar, Vadodara',
            'bank_account_no' => '59200000008899', 'bank_ifsc' => 'HDFC0003001',
            'authorized_signatory' => 'Pratik Patel', 'signatory_phone' => '9574799722']);

        $customer = Customer::factory()->create(array_merge(['company_id' => $cid, 'name' => 'IDEAL AGRI CARE', 'state' => 'Gujarat', 'state_code' => '24',
            'city' => 'Ahmedabad', 'pincode' => '382470', 'gstin' => null, 'pan' => null, 'contact_person' => null, 'whatsapp_no' => null, 'email' => null], $customerOver));
        $ceiling = PanelType::factory()->create(['company_id' => $cid, 'name' => 'Signature Ceiling Panel']);
        $wall    = PanelType::factory()->create(['company_id' => $cid, 'name' => 'Signature Wall Panel']);

        $acc = fn ($a, $q, $r, $type = 'standard', $extra = []) => array_merge(
            ['type' => $type, 'accessory_id' => $a->id, 'qty' => $q, 'rate' => $r, 'description' => $a->name, 'unit' => $a->unit], $extra);
        $single = $this->acc($cid, 'Clean Room Manual Swing Door - Single', 'NOS', '73083000');
        $double = $this->acc($cid, 'Clean Room Manual Swing Door - Double', 'NOS', '73083000');

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/quotations', array_merge([
            'customer_id' => $customer->id, 'project_name' => 'IDEAL AGRI CARE - Nandasan, Kadi',
            'project_location' => 'Nandasan (Navapura), Ta. Kadi, Dist. Mehsana', 'quality_grade' => 'High',
            'advance_pct' => 50, 'validity_days' => 10,
            'panel_rows' => [$this->panelRow($ceiling, 'Ceiling', 30, 4450, 30, 1075), $this->panelRow($wall, 'Wall', 50, 2750, 84, 1275)],
            'accessories' => [
                $acc($this->acc($cid, 'Aluminium T Channel', 'MTR', '76169990'), 15, 950),
                $acc($this->acc($cid, '3D Corner', 'NOS', '76169990'), 25, 320),
                $acc($this->acc($cid, 'Aluminium Coving', 'MTR', '76169990'), 130, 325),
                $acc($this->acc($cid, 'C Channel', 'MTR', '73089090'), 100, 130),
                $acc($this->acc($cid, 'Aluminium Outer Corner', 'MTR', '76169990'), 9, 450),
                $acc($single, 4, 17500, 'door', ['door_type' => 'hinged_door', 'door_width' => 1000, 'door_height' => 2100]),
                $acc($double, 3, 24500, 'door', ['door_type' => 'hinged_door', 'door_width' => 2000, 'door_height' => 2100]),
                ['type' => 'installation', 'description' => 'Installation', 'qty' => 364.5, 'rate' => 300, 'unit' => 'MTR'],
            ],
        ], $over))->assertStatus(201);

        return [$admin, Quotation::find($res->json('data.id'))];
    }

    private function html(Quotation $q): string
    {
        return view('quotations.pdf_ledger', ['quotation' => $q])->render();
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page(?!s)#', $pdf);
    }

    public function test_the_real_uma_quotation_reconciles_with_the_approved_design(): void
    {
        [, $q] = $this->umaQuotation();
        $q = $q->fresh();
        $this->assertEquals(911476.00, (float) $q->total_amount);   // fixture sanity

        $html = $this->html($q);

        foreach (['911,476.00', '772,437.50', '438,037.50', '225,050.00', '109,350.00', '69,519.38', '455,738.00',
                  'SCP-', 'IDEAL AGRI CARE', 'SUPPLY OF SIGNATURE PUF PANEL MAKE CONTINUOUS LINE PUF PANEL — CEILING 30MM THICK.',
                  '0.50 mm PPGL · Off White · RAL 9002 · Plain', 'PUF · 40 (±2) kg/m³', 'Guard film · Cello tape',
                  'Hinged door · 1000 × 2100 mm', 'Hinged door · 2000 × 2100 mm', 'INSTALLATION / SITE WORK', '994568',
                  'EXTRA AS ACTUAL', 'Gujarat (24)', 'Intra-state'] as $needle) {
            $this->assertStringContainsString($needle, $html, "Missing: {$needle}");
        }
        $this->assertStringContainsString('Rupees Nine Lakh Eleven Thousand Four Hundred and Seventy Six Only', $html);
    }

    public function test_the_gst_summary_groups_by_code_and_matches_the_stored_totals(): void
    {
        [, $q] = $this->umaQuotation();
        $doc = app(\App\Services\QuotationDocumentService::class)->build($q->fresh());

        $rows = collect($doc['hsn']['rows'])->keyBy('hsn');
        $this->assertEquals(438037.50, $rows['39259010']['taxable']);
        $this->assertEquals(68550.00,  $rows['76169990']['taxable']);
        $this->assertEquals(13000.00,  $rows['73089090']['taxable']);
        $this->assertEquals(143500.00, $rows['73083000']['taxable']);
        $this->assertEquals(109350.00, $rows['994568']['taxable']);
        $this->assertEquals(39423.38, $rows['39259010']['cgst']);
        $this->assertEquals(9841.50,  $rows['994568']['sgst']);

        // Rows add up to the document total (to the paisa), and the total row is the stored tax.
        $this->assertEqualsWithDelta(772437.50, $rows->sum('taxable'), 0.001);
        $this->assertEqualsWithDelta(139038.75, $rows->sum('tax'), 0.011);
        $this->assertEquals(139038.75, $doc['hsn']['total']['tax']);
    }

    public function test_bank_details_use_the_real_field_names(): void
    {
        [, $q] = $this->umaQuotation();
        $html = $this->html($q->fresh());

        // The Classic template reads bank_account / ifsc_code, which do not exist, and prints a dash.
        $this->assertStringContainsString('59200000008899', $html);
        $this->assertStringContainsString('HDFC0003001', $html);
        $this->assertStringContainsString('HDFC Bank Ltd', $html);
        $this->assertStringContainsString('Pratik Patel', $html);
    }

    public function test_terms_have_their_placeholders_filled_and_nothing_raw_leaks(): void
    {
        [, $q] = $this->umaQuotation();
        $html = $this->html($q->fresh());

        $this->assertStringContainsString('<b>50%</b> of order value including taxes', $html);
        $this->assertStringContainsString('<b>₹ 455,738.00</b>', $html);
        $this->assertStringContainsString('in favour of <b>Signature PUF Panel</b>', $html);
        $this->assertStringContainsString('Subject to <b>Vadodara</b> jurisdiction', $html);
        $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $html, 'An unfilled placeholder reached the page.');
    }

    public function test_discount_igst_and_revision_appear_only_when_they_apply(): void
    {
        [, $plain] = $this->umaQuotation();
        $html = $this->html($plain->fresh());
        $this->assertStringNotContainsString('Discount (', $html);
        $this->assertStringNotContainsString('REVISION', $html);
        $this->assertStringContainsString('CGST @ 9%', $html);

        [, $q] = $this->umaQuotation(['discount_pct' => 10], ['state' => 'Maharashtra', 'state_code' => '27']);
        $q->forceFill(['revision_number' => 2])->save();

        $html = $this->html($q->fresh());
        $this->assertStringContainsString('Discount (10%)', $html);
        $this->assertStringContainsString('IGST @ 18%', $html);
        $this->assertStringContainsString('IGST 18%', $html);            // GST summary heading
        $this->assertStringNotContainsString('CGST @ 9%', $html);
        $this->assertStringContainsString('REVISION 2', $html);
    }

    public function test_a_short_panel_is_flagged_as_double_length_with_its_note(): void
    {
        [$admin, $q] = $this->umaQuotation();
        $pt = PanelType::factory()->create(['company_id' => $admin->company_id]);
        $this->actingAs($admin, 'sanctum')->putJson("/api/quotations/{$q->id}", [
            'customer_id' => $q->customer_id,
            'panel_rows' => [$this->panelRow($pt, 'Wall', 50, 1800, 6, 1000)],
        ])->assertStatus(200);

        $html = $this->html($q->fresh());
        $this->assertStringContainsString('class="dl"', $html);
        $this->assertStringContainsString('produced at double length', $html);
    }

    public function test_hostile_text_and_colour_cannot_inject_markup_or_css(): void
    {
        [$admin, $q] = $this->umaQuotation();
        $admin->company->update(['name' => 'Evil <script>alert(1)</script> Co']);
        $admin->company->forceFill(['primary_color' => '#abc;}body{display:none'])->save();
        $q->customer->update(['name' => '<img src=x onerror=alert(2)>']);

        $html = $this->html($q->fresh());

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('display:none', $html);
        $this->assertStringContainsString('#2B50E0', $html, 'A bad brand colour should fall back to the default.');
    }

    public function test_a_valid_brand_colour_is_used(): void
    {
        [$admin, $q] = $this->umaQuotation();
        $admin->company->forceFill(['primary_color' => '#1F7A5C'])->save();

        $this->assertStringContainsString('#1F7A5C', $this->html($q->fresh()));
    }

    public function test_it_makes_a_small_valid_pdf_and_leaves_classic_alone(): void
    {
        [, $q] = $this->umaQuotation();
        $svc = app(QuotationPdfService::class);

        $ledger = $svc->generate($q->fresh(), 'quotations.pdf_ledger')->getContent();
        $this->assertStringStartsWith('%PDF', $ledger);
        $this->assertLessThan(400 * 1024, strlen($ledger));
        $this->assertGreaterThanOrEqual(2, $this->pageCount($ledger), 'Terms should start on their own page.');

        // Classic is still the default and renders as before.
        $this->assertSame('quotations.pdf', app(DocumentTemplateService::class)->viewFor($q->company_id, 'quotation', 'quotations.pdf'));
        $classic = $svc->generate($q->fresh())->getContent();
        $this->assertStringStartsWith('%PDF', $classic);
        $this->assertFalse(app(DocumentTemplateService::class)->wantsPageNumbers('quotation', 'quotations.pdf'));
    }

    public function test_choosing_the_template_for_a_company_selects_it_without_touching_others(): void
    {
        [$admin, $q] = $this->umaQuotation();
        $other = $this->createAdminUser();
        $svc = app(DocumentTemplateService::class);

        $svc->setTemplate($admin->company_id, 'quotation', 'ledger');

        $this->assertSame('quotations.pdf_ledger', $svc->viewFor($admin->company_id, 'quotation', 'quotations.pdf'));
        $this->assertSame('quotations.pdf', $svc->viewFor($other->company_id, 'quotation', 'quotations.pdf'));
        $this->assertTrue($svc->wantsPageNumbers('quotation', 'quotations.pdf_ledger'));
    }

    public function test_many_lines_flow_across_pages_without_error(): void
    {
        [$admin, $q] = $this->umaQuotation();
        $extra = Accessory::factory()->count(45)->create(['company_id' => $admin->company_id, 'hsn_code' => '73089090']);
        $pt = PanelType::factory()->create(['company_id' => $admin->company_id]);
        $this->actingAs($admin, 'sanctum')->putJson("/api/quotations/{$q->id}", [
            'customer_id' => $q->customer_id,
            'panel_rows' => [$this->panelRow($pt, 'Wall', 50, 3000, 20, 1200), $this->panelRow($pt, 'Roof', 50, 3500, 10, 1300)],
            'accessories' => $extra->map(fn ($a) => ['type' => 'standard', 'accessory_id' => $a->id, 'qty' => 3, 'rate' => 110])->all(),
        ])->assertStatus(200);

        $pdf = app(QuotationPdfService::class)->generate($q->fresh(), 'quotations.pdf_ledger')->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThanOrEqual(4, $this->pageCount($pdf));
        $this->assertLessThan(500 * 1024, strlen($pdf));
    }

    public function test_a_sparse_quotation_prints_dashes_instead_of_failing(): void
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'address_line1' => '', 'city' => '', 'state' => '', 'state_code' => '', 'pincode' => '']);
        $pt = PanelType::factory()->create(['company_id' => $admin->company_id]);
        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/quotations', [
            'customer_id' => $customer->id, 'panel_rows' => [$this->panelRow($pt, 'Wall', 50, 3000, 2, 0)],
        ])->assertStatus(201);

        $pdf = app(QuotationPdfService::class)->generate(Quotation::find($res->json('data.id')), 'quotations.pdf_ledger')->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
