<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PanelType;
use App\Models\Quotation;
use Tests\TestCase;

/**
 * Step 0.3 of QUOTATION_TEMPLATES_PLAN.md. The legal entity name, document title, certification line and the
 * terms / exclusions were typed into the template for every tenant. They are company settings now; a company
 * that sets none of them gets exactly the standard wording.
 */
class CompanyDocumentSettingsTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function quotationFor($admin): Quotation
    {
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $pt = PanelType::factory()->create(['company_id' => $admin->company_id]);
        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/quotations', [
            'customer_id' => $customer->id, 'advance_pct' => 50,
            'panel_rows' => [[
                'panel_type_id' => $pt->id, 'thickness' => 50, 'density_type' => 'PUF', 'density_kgm3' => 40,
                'top_skin_material' => 'PPGI', 'top_skin_thickness' => '0.40', 'top_surface' => 'PLAIN',
                'bottom_skin_material' => 'PPGI', 'bottom_skin_thickness' => '0.40',
                'sizes' => [['length_mm' => 3000, 'nos' => 10, 'rate_per_sqm' => 1000]],
            ]],
        ])->assertStatus(201);

        return Quotation::find($res->json('data.id'));
    }

    private function html(Quotation $q): string
    {
        return view('quotations.pdf_ledger', ['quotation' => $q->fresh()])->render();
    }

    private function saveCompany($admin, array $extra): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($admin, 'sanctum')->putJson('/api/company', array_merge(['name' => $admin->company->name], $extra));
    }

    public function test_the_settings_are_saved_and_returned_with_the_standard_wording_to_start_from(): void
    {
        $admin = $this->createAdminUser();

        $res = $this->saveCompany($admin, [
            'legal_name' => 'UMA SIGNATURE PUF PANEL LLP', 'quotation_title' => 'QUOTATION', 'certification_line' => 'AN ISO 9001:2015 COMPANY',
            'quotation_terms' => [['title' => 'Delivery', 'text' => 'Within 10 days.']], 'quotation_exclusions' => ['Crane hire.'],
        ])->assertOk();

        $this->assertSame('UMA SIGNATURE PUF PANEL LLP', $res->json('data.legal_name'));
        $this->assertSame('QUOTATION', $res->json('data.quotation_title'));
        $this->assertSame([['title' => 'Delivery', 'text' => 'Within 10 days.']], $res->json('data.quotation_terms'));
        $this->assertSame(['Crane hire.'], $res->json('data.quotation_exclusions'));
        $this->assertCount(12, $res->json('data.default_quotation_terms'));
        $this->assertCount(6, $res->json('data.default_quotation_exclusions'));
    }

    public function test_bad_values_are_rejected_not_stored(): void
    {
        $admin = $this->createAdminUser();

        $this->saveCompany($admin, ['quotation_title' => 'INVOICE'])->assertStatus(422);
        $this->saveCompany($admin, ['quotation_terms' => [['title' => 'No text']]])->assertStatus(422);
        $this->saveCompany($admin, ['quotation_terms' => array_fill(0, 31, ['title' => 'T', 'text' => 'x'])])->assertStatus(422);
        $this->saveCompany($admin, ['quotation_exclusions' => [str_repeat('x', 301)]])->assertStatus(422);
        $this->assertNull($admin->company->fresh()->quotation_title);
    }

    public function test_only_a_company_admin_can_change_them(): void
    {
        $user = $this->createTestUser();   // not a company admin

        $this->actingAs($user, 'sanctum')->putJson('/api/company', ['name' => 'X', 'legal_name' => 'Hijack LLP'])->assertStatus(403);
        $this->assertNull($user->company->fresh()->legal_name);
    }

    public function test_an_empty_list_means_use_the_standard_wording(): void
    {
        $admin = $this->createAdminUser();
        $this->saveCompany($admin, ['quotation_terms' => [['title' => 'A', 'text' => 'b']]])->assertOk();

        $this->saveCompany($admin, ['quotation_terms' => [], 'quotation_exclusions' => []])->assertOk();

        $this->assertNull($admin->company->fresh()->quotation_terms);
        $this->assertStringContainsString('Scope is limited to supply of material', $this->html($this->quotationFor($admin)));
    }

    public function test_the_legal_name_is_used_for_pay_to_in_favour_of_and_the_signature_block(): void
    {
        $admin = $this->createAdminUser();
        $admin->company->update(['name' => 'Signature PUF Panel']);
        $q = $this->quotationFor($admin);

        // Not set: falls back to the trading name.
        $html = $this->html($q);
        $this->assertStringContainsString('in favour of <b>Signature PUF Panel</b>', $html);
        $this->assertStringContainsString('For SIGNATURE PUF PANEL', $html);

        $this->saveCompany($admin, ['legal_name' => 'UMA SIGNATURE PUF PANEL LLP'])->assertOk();
        $html = $this->html($q);
        $this->assertStringContainsString('in favour of <b>UMA SIGNATURE PUF PANEL LLP</b>', $html);
        $this->assertStringContainsString('For UMA SIGNATURE PUF PANEL LLP', $html);
        $this->assertStringContainsString('<b>UMA SIGNATURE PUF PANEL LLP</b>', $html);   // "Account name"
    }

    public function test_the_title_switches_the_heading_and_the_wording_that_names_the_document(): void
    {
        $admin = $this->createAdminUser();
        $q = $this->quotationFor($admin);

        $default = $this->html($q);
        $this->assertStringContainsString('PROFORMA INVOICE', $default);
        $this->assertStringContainsString('against <b>Proforma Invoice</b> at least 2 days', $default);
        $this->assertStringContainsString('This is a Proforma Invoice and not a valid tax document.', $default);

        $this->saveCompany($admin, ['quotation_title' => 'QUOTATION'])->assertOk();
        $html = $this->html($q);
        $this->assertStringNotContainsString('PROFORMA INVOICE', $html);
        $this->assertStringContainsString('class="title">QUOTATION', $html);
        $this->assertStringContainsString('against <b>quotation</b> at least 2 days', $html);
        $this->assertStringContainsString('This is a quotation and not a valid tax document.', $html);
    }

    public function test_the_certification_line_prints_only_when_set(): void
    {
        $admin = $this->createAdminUser();
        $admin->company->update(['gstin' => '24AAFFU9050M1ZS']);
        $q = $this->quotationFor($admin);

        $this->assertStringNotContainsString('ISO 9001', $this->html($q));   // no claim for a company that has not made it

        $this->saveCompany($admin, ['gstin' => '24AAFFU9050M1ZS', 'certification_line' => 'AN ISO 9001:2015 COMPANY'])->assertOk();
        $this->assertStringContainsString('AN ISO 9001:2015 COMPANY', $this->html($q));
    }

    public function test_the_companys_own_terms_replace_the_standard_ones_and_cannot_inject_markup(): void
    {
        $admin = $this->createAdminUser();
        $q = $this->quotationFor($admin);
        $this->saveCompany($admin, [
            'quotation_terms' => [
                ['title' => 'Delivery', 'text' => 'Within 10 days. <script>alert(1)</script>'],
                ['title' => 'Payment', 'text' => 'Pay {advance_amount} now.'],
            ],
            'quotation_exclusions' => ['Crane hire & <b>fuel</b>.'],
        ])->assertOk();

        $html = $this->html($q);

        $this->assertStringContainsString('Within 10 days.', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('Pay <b>₹ ', $html);                      // placeholder still filled
        $this->assertStringNotContainsString('Scope is limited to supply', $html);  // the standard list is replaced
        $this->assertStringContainsString('Crane hire &amp; &lt;b&gt;fuel&lt;/b&gt;.', $html);
        $this->assertStringNotContainsString('Unloading and safe storage', $html);
    }
}
