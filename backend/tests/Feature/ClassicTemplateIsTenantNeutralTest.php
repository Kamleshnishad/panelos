<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PanelType;
use App\Models\Quotation;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * BG-01 / BG-02. The Classic quotation template had one company's factory address ("Survey No 158/1, Dhanora, Karjan,
 * Vadodara") and an "AN ISO 9001:2015 COMPANY" claim typed into it, so every company on the platform sent its
 * customers a document naming another company's plant and claiming a certification it may not hold.
 */
class ClassicTemplateIsTenantNeutralTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    /** @return array{0:\App\Models\Company,1:string} the company and the Classic HTML of one of its quotations */
    private function classicFor(array $company): array
    {
        $admin = $this->createAdminUser();
        $admin->company->update($company);
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $pt = PanelType::factory()->create(['company_id' => $admin->company_id]);
        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/quotations', [
            'customer_id' => $customer->id, 'panel_rows' => $this->panelRows($pt->id, 5, 900),
        ])->assertStatus(201);

        return [$admin->company->fresh(), view('quotations.pdf', ['quotation' => Quotation::find($res->json('data.id'))])->render()];
    }

    public function test_another_company_never_sees_the_original_factory_address(): void
    {
        [, $html] = $this->classicFor([
            'name' => 'Cold Chain Panels Pvt Ltd', 'address_line1' => 'Plot 9, MIDC Chakan', 'city' => 'Pune', 'state' => 'Maharashtra',
            'pincode' => '410501', 'gstin' => '27AABCC1234D1Z5', 'certification_line' => null,
        ]);

        $this->assertStringContainsString('All prices are Ex-Works from our factory, Plot 9, MIDC Chakan, Pune, Maharashtra 410501.', $html);
        foreach (['Dhanora', 'Karjan', 'Survey No 158/1'] as $other) {
            $this->assertStringNotContainsString($other, $html, "Another company's address ({$other}) leaked into this company's quotation.");
        }
    }

    public function test_no_certification_is_claimed_unless_the_company_has_set_one(): void
    {
        [, $without] = $this->classicFor(['gstin' => '27AABCC1234D1Z5', 'certification_line' => null]);
        $this->assertStringNotContainsString('ISO', $without);
        $this->assertStringContainsString('GSTIN: <strong>27AABCC1234D1Z5</strong>', $without);

        [, $blank] = $this->classicFor(['gstin' => '27AABCC1234D1Z5', 'certification_line' => '   ']);
        $this->assertStringNotContainsString('ISO', $blank, 'A blank setting must not print anything.');

        [, $with] = $this->classicFor(['gstin' => '24AAFFU9050M1ZS', 'certification_line' => 'AN ISO 9001:2015 COMPANY']);
        $this->assertStringContainsString('AN ISO 9001:2015 COMPANY', $with);
    }

    public function test_the_certification_prints_even_without_a_gstin(): void
    {
        [, $html] = $this->classicFor(['gstin' => null, 'certification_line' => 'AN ISO 9001:2015 COMPANY']);

        $this->assertStringContainsString('AN ISO 9001:2015 COMPANY', $html);
        // The seller header (the buyer box has its own GSTIN, so look only between the logo cell and the title).
        $header = substr($html, strpos($html, 'class="logo-cell"'), strpos($html, 'class="title-cell"') - strpos($html, 'class="logo-cell"'));
        $this->assertStringNotContainsString('GSTIN', $header);
    }

    public function test_a_company_with_no_address_gets_a_clean_sentence(): void
    {
        [, $html] = $this->classicFor(['address_line1' => '', 'city' => '', 'state' => '', 'pincode' => '']);

        $this->assertStringContainsString('All prices are Ex-Works from our factory.', $html);
        $this->assertStringNotContainsString('factory,', $html, 'No dangling comma when there is no address.');
    }

    public function test_cheques_and_the_signature_block_name_the_legal_entity_when_one_is_set(): void
    {
        [, $with] = $this->classicFor(['name' => 'Signature PUF Panel', 'legal_name' => 'UMA SIGNATURE PUF PANEL LLP']);
        $this->assertStringContainsString('in favour of UMA SIGNATURE PUF PANEL LLP', $with);
        $this->assertSame(2, substr_count($with, 'For UMA SIGNATURE PUF PANEL LLP'), 'Both signature blocks (page 1 and the terms page).');
        $this->assertStringNotContainsString('in favour of Signature PUF Panel', $with);

        // Without one, the trading name is used (and the header always shows the trading name).
        [, $without] = $this->classicFor(['name' => 'Signature PUF Panel', 'legal_name' => null]);
        $this->assertStringContainsString('in favour of Signature PUF Panel', $without);
        $this->assertStringContainsString('For Signature PUF Panel', $without);
    }

    public function test_the_company_address_is_escaped(): void
    {
        [, $html] = $this->classicFor(['address_line1' => '<script>alert(1)</script>', 'city' => 'Pune']);

        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    /**
     * A cheap guard for the whole class of fault: no view may have a specific company's identity typed into it.
     * Per-company text belongs in Settings -> Company (or config), never in a template every company shares.
     */
    public function test_no_view_has_a_specific_companys_details_typed_in(): void
    {
        $forbidden = ['Dhanora', 'Karjan', 'Signature PUF', 'signaturepuf', '24AAFFU', 'UMA SIGNATURE', 'ISO 9001'];
        $offences = [];

        foreach ((new Finder())->files()->in(resource_path('views'))->name('*.php') as $file) {
            $text = $file->getContents();
            foreach ($forbidden as $needle) {
                if (stripos($text, $needle) !== false) {
                    $offences[] = "{$file->getRelativePathname()} contains \"{$needle}\"";
                }
            }
        }

        $this->assertSame([], $offences, "A company's own details are typed into a shared template:\n" . implode("\n", $offences));
    }
}
