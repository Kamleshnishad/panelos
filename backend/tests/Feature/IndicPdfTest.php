<?php

namespace Tests\Feature;

use App\Models\Quotation;
use App\Support\IndicPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsUmaQuotation;

/** PB-084: Gujarati / Hindi text printed as empty boxes on every PDF. */
class IndicPdfTest extends TestCase
{
    use RefreshDatabase, BuildsUmaQuotation;

    private function pdf($res): string
    {
        return $res->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse ? $res->streamedContent() : $res->getContent();
    }

    public function test_english_only_html_is_left_exactly_as_it_was(): void
    {
        $html = '<html><head><style>p{color:red}</style></head><body><p title="x">Plain text 123</p></body></html>';
        $this->assertSame($html, IndicPdf::apply($html));
    }

    public function test_each_run_is_wrapped_in_its_own_font_and_tags_and_attributes_are_untouched(): void
    {
        $out = IndicPdf::apply('<html><head></head><body><p title="ગુજરાત">Site: ગુજરાત પેનલ and हिन्दी, 5 nos</p></body></html>');

        $this->assertStringContainsString('title="ગુજરાત"', $out);   // an attribute is never wrapped
        $this->assertStringContainsString("<span style=\"font-family: 'Noto Sans Gujarati';\">ગુજરાત પેનલ</span> and", $out);   // two words stay one run
        $this->assertStringContainsString("<span style=\"font-family: 'Noto Sans Devanagari';\">हिन्दी</span>, 5 nos", $out);
        $this->assertStringContainsString('@font-face{font-family:\'Noto Sans Gujarati\'', $out);
        $this->assertStringContainsString('@font-face{font-family:\'Noto Sans Devanagari\'', $out);
        $this->assertLessThan(strpos($out, '</head>'), strpos($out, '@font-face'));
    }

    public function test_a_document_with_only_one_script_declares_only_that_font(): void
    {
        $out = IndicPdf::apply('<html><head></head><body>ગુજરાત</body></html>');
        $this->assertStringContainsString('Noto Sans Gujarati', $out);
        $this->assertStringNotContainsString('Devanagari', $out);
    }

    public function test_invalid_utf8_is_passed_through_not_lost(): void
    {
        $bad = "<html><body>caf\xE9</body></html>";
        $this->assertSame($bad, IndicPdf::apply($bad));
    }

    public function test_quotation_pdf_embeds_the_indic_fonts_and_an_english_one_does_not(): void
    {
        [$admin, $q] = $this->umaQuotation(['project_name' => 'IDEAL AGRI - નંદાસણ', 'notes' => 'हिन्दी नोट']);
        $this->actingAs($admin, 'sanctum');

        $pdf = $this->pdf($this->get("/api/quotations/{$q->id}/pdf"));
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('NotoSansGujarati', $pdf);
        $this->assertStringContainsString('NotoSansDevanagari', $pdf);

        [, $plain] = $this->umaQuotation();
        $this->assertStringNotContainsString('Noto', $this->pdf($this->get("/api/quotations/{$plain->id}/pdf")));
    }
}
