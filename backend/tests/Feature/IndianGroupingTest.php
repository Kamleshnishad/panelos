<?php

namespace Tests\Feature;

use App\Models\Quotation;
use App\Support\Inr;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * UX-06. The web screens group digits the Indian way (9,11,476.00) but PDFs and e-mails used PHP's number_format and
 * printed 911,476.00, so the same figure read differently on screen and on paper.
 */
class IndianGroupingTest extends TestCase
{
    use \Tests\Traits\BuildsUmaQuotation;

    public function test_it_groups_the_indian_way(): void
    {
        $cases = [
            [0, '0.00'], [5, '5.00'], [999.5, '999.50'], [1000, '1,000.00'], [12345.678, '12,345.68'],
            [99999.99, '99,999.99'], [100000, '1,00,000.00'], [911476, '9,11,476.00'], [1234567.8, '12,34,567.80'],
            [12345678.9, '1,23,45,678.90'], [-911476, '-9,11,476.00'], [-0.001, '0.00'], ['911476.5', '9,11,476.50'], [null, '0.00'],
        ];
        foreach ($cases as [$in, $out]) {
            $this->assertSame($out, Inr::fmt($in), 'for ' . var_export($in, true));
        }
        $this->assertSame('9,11,476', Inr::fmt(911476, 0));
        $this->assertSame('1,00,000.0', Inr::fmt(100000, 1));
        $this->assertSame('3,12,930.4560', Inr::fmt(312930.456, 4));
    }

    public function test_no_customer_facing_file_still_uses_phps_three_digit_grouping(): void
    {
        $offenders = [];
        $finder = (new Finder())->files()->name('*.php')->in([base_path('app'), base_path('resources/views')]);
        foreach ($finder as $file) {
            $path = str_replace('\\', '/', $file->getRelativePathname());
            // Skin thicknesses (0.40 mm) never reach a thousand, so grouping is irrelevant there.
            if (str_ends_with($path, 'Support/Inr.php') || str_ends_with($path, 'Services/QuotationService.php')) {
                continue;
            }
            if (str_contains($file->getContents(), 'number_format(')) {
                $offenders[] = $path;
            }
        }
        $this->assertSame([], $offenders, 'Use App\Support\Inr::fmt() so amounts read 9,11,476.00 (UX-06).');
    }

    public function test_every_quotation_template_prints_lakh_amounts_the_indian_way(): void
    {
        [, $q] = $this->umaQuotation();
        $q = $q->fresh();
        $this->assertGreaterThan(100000, (float) $q->total_amount, 'The fixture must be big enough to need lakh grouping.');

        foreach ((require base_path('config/document_templates.php'))['quotation'] as $key => $meta) {
            $text = html_entity_decode(strip_tags(preg_replace('#<style.*?</style>#s', '', view($meta['view'], ['quotation' => Quotation::find($q->id)])->render())));

            $this->assertStringContainsString(Inr::fmt($q->total_amount), $text, "{$key}: total missing");
            $this->assertDoesNotMatchRegularExpression('/\d,\d{3},\d{3}\b/', $text, "{$key}: a Western-grouped lakh amount (e.g. 911,476.00) is still printed.");
            $this->assertMatchesRegularExpression('/\d{1,2},\d{2},\d{3}\.\d{2}/', $text, "{$key}: no Indian-grouped amount found.");
        }
    }
}
