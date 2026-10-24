<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Customer;
use App\Models\PanelType;
use App\Models\Quotation;
use App\Services\QuotationPdfService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Step 0.6 of QUOTATION_TEMPLATES_PLAN.md — one gate every quotation template must pass.
 *
 * It loops over everything registered in config/document_templates.php, so a template added later is
 * checked the moment it is registered: valid PDF, small, quick, the facts that must be on every quotation
 * present, sensible pages, and it survives a sparse quotation and one with dozens of lines.
 * (What a page looks like is still judged by eye on a rendered image — this catches breakage, not taste.)
 */
class QuotationTemplateHarnessTest extends TestCase
{
    use \Tests\Traits\BuildsUmaQuotation;

    private const MAX_BYTES = 400 * 1024;
    private const MAX_SECONDS = 8.0;   // generous: the CI machine is slower than a laptop

    /** @return array<string, array{0:string, 1:string}> template key => [view, name] */
    public static function templates(): array
    {
        $out = [];
        // Data providers run before the application boots, so read the registry file itself.
        foreach ((require __DIR__ . '/../../config/document_templates.php')['quotation'] as $key => $meta) {
            $out[$key] = [$key, $meta['view']];
        }
        return $out;
    }

    private function pages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page(?!s)#', $pdf);
    }

    /** Plain text of the document as a reader sees it (the HTML, tags and entities stripped). */
    private function text(string $view, Quotation $q): string
    {
        return html_entity_decode(strip_tags(preg_replace('#<style.*?</style>#s', '', view($view, ['quotation' => $q])->render())));
    }

    #[DataProvider('templates')]
    public function test_every_template_renders_the_real_quotation_quickly_and_small(string $key, string $view): void
    {
        [, $q] = $this->umaQuotation();
        $svc = app(QuotationPdfService::class);

        $t0 = microtime(true);
        $pdf = $svc->generate($q->fresh(), $view)->getContent();
        $seconds = microtime(true) - $t0;

        $this->assertStringStartsWith('%PDF', $pdf, "{$key}: not a PDF");
        $this->assertLessThan(self::MAX_BYTES, strlen($pdf), "{$key}: PDF is " . intdiv(strlen($pdf), 1024) . ' KB');
        $this->assertLessThan(self::MAX_SECONDS, $seconds, "{$key}: took " . round($seconds, 1) . ' s');
        $this->assertGreaterThanOrEqual(2, $this->pages($pdf), "{$key}: terms should be on a page of their own");
        $this->assertLessThanOrEqual(6, $this->pages($pdf), "{$key}: a 10-line quotation should not need this many pages");
    }

    #[DataProvider('templates')]
    public function test_every_template_carries_the_facts_every_quotation_must_show(string $key, string $view): void
    {
        [, $q] = $this->umaQuotation();
        $q = $q->fresh();
        $text = $this->text($view, $q);

        $must = [
            'quotation number' => $q->quotation_no,
            'buyer'            => 'IDEAL AGRI CARE',
            'grand total'      => '911,476.00',
            'taxable amount'   => '772,437.50',
            'GST'              => '69,519.38',
            'advance'          => '455,738.00',
            'panel quantity'   => '133.50',
            'panel rate'       => '1,075.00',
            'installation'     => '109,350.00',
            'a door'           => '73083000',
            'seller GSTIN'     => '24AAFFU9050M1ZS',
            'bank account'     => '59200000008899',
            'IFSC'             => 'HDFC0003001',
        ];
        foreach ($must as $what => $needle) {
            $this->assertStringContainsString($needle, $text, "{$key}: the {$what} ({$needle}) is missing");
        }

        // Terms wording differs between templates, but every one must state these.
        foreach (['payment', 'delivery', 'validity', 'exclusions'] as $term) {
            $this->assertStringContainsStringIgnoringCase($term, $text, "{$key}: the {$term} terms are missing");
        }
    }

    #[DataProvider('templates')]
    public function test_every_template_survives_a_sparse_quotation(string $key, string $view): void
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'address_line1' => '', 'city' => '', 'state' => '',
            'state_code' => '', 'pincode' => '', 'gstin' => null, 'pan' => null, 'email' => null, 'contact_person' => null]);
        $pt = PanelType::factory()->create(['company_id' => $admin->company_id]);
        $admin->company->update(['logo' => null, 'bank_name' => null, 'bank_account_no' => null, 'bank_ifsc' => null,
            'authorized_signatory' => null, 'phone' => null, 'email' => null]);
        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/quotations', [
            'customer_id' => $customer->id, 'panel_rows' => [$this->panelRow($pt, 'Wall', 50, 3000, 2, 0)],
        ])->assertStatus(201);

        $pdf = app(QuotationPdfService::class)->generate(Quotation::find($res->json('data.id')), $view)->getContent();

        $this->assertStringStartsWith('%PDF', $pdf, "{$key}: a bare quotation broke it");
    }

    #[DataProvider('templates')]
    public function test_every_template_flows_a_long_quotation_across_pages(string $key, string $view): void
    {
        [$admin, $q] = $this->umaQuotation();
        $extra = Accessory::factory()->count(45)->create(['company_id' => $admin->company_id, 'hsn_code' => '73089090']);
        $pt = PanelType::factory()->create(['company_id' => $admin->company_id]);
        $this->actingAs($admin, 'sanctum')->putJson("/api/quotations/{$q->id}", [
            'customer_id' => $q->customer_id,
            'panel_rows' => [$this->panelRow($pt, 'Wall', 50, 3000, 20, 1200), $this->panelRow($pt, 'Roof', 50, 1800, 10, 1300)],
            'accessories' => $extra->map(fn ($a) => ['type' => 'standard', 'accessory_id' => $a->id, 'qty' => 3, 'rate' => 110])->all(),
        ])->assertStatus(200);
        $q = $q->fresh();

        $pdf = app(QuotationPdfService::class)->generate($q, $view)->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThanOrEqual(4, $this->pages($pdf), "{$key}: 45 lines should spill onto more pages");
        $this->assertLessThan(self::MAX_BYTES + 100 * 1024, strlen($pdf));
        // The totals still say the same thing however many pages it took.
        $this->assertStringContainsString(number_format((float) $q->total_amount, 2), $this->text($view, $q), "{$key}: grand total lost");
    }

    #[DataProvider('templates')]
    public function test_every_template_shows_igst_and_a_discount_when_they_apply(string $key, string $view): void
    {
        [, $q] = $this->umaQuotation(['discount_pct' => 10], ['state' => 'Maharashtra', 'state_code' => '27']);
        $q = $q->fresh();
        $this->assertTrue((bool) $q->is_inter_state);
        $text = $this->text($view, $q);

        $this->assertStringContainsString('IGST', $text, "{$key}: inter-state supply must show IGST");
        $this->assertStringContainsString(number_format((float) $q->igst_amount, 2), $text, "{$key}: IGST amount missing");
        $this->assertStringContainsString(number_format((float) $q->discount_amount, 2), $text, "{$key}: discount amount missing");
    }

    #[DataProvider('templates')]
    public function test_a_hostile_company_or_customer_name_cannot_inject_markup(string $key, string $view): void
    {
        [$admin, $q] = $this->umaQuotation();
        $admin->company->update(['name' => 'Evil <script>alert(1)</script> Co']);
        $q->customer->update(['name' => '<img src=x onerror=alert(2)>']);

        $html = view($view, ['quotation' => $q->fresh()])->render();

        $this->assertStringNotContainsString('<script>alert(1)', $html, "{$key}: company name injected markup");
        $this->assertStringNotContainsString('<img src=x', $html, "{$key}: customer name injected markup");
    }

    public function test_the_registry_itself_is_sound(): void
    {
        foreach (config('document_templates') as $docType => $templates) {
            $this->assertArrayHasKey('classic', $templates, "{$docType} must keep a 'classic' fallback");
            foreach ($templates as $key => $meta) {
                $this->assertTrue(view()->exists($meta['view']), "{$docType}.{$key} points at a missing view {$meta['view']}");
                $this->assertNotSame('', trim($meta['name'] ?? ''), "{$docType}.{$key} has no name");
                $this->assertNotSame('', trim($meta['description'] ?? ''), "{$docType}.{$key} has no description");
            }
        }
    }
}
