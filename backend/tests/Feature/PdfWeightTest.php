<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PanelType;
use App\Models\Quotation;
use App\Services\QuotationPdfService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PB-022. Every generated PDF was ~1 MB and took 8-12 s on the server, which read as
 * "the system has frozen". Two causes: font subsetting was off (whole DejaVu files
 * embedded), and the company logo went in at full upload size — DomPDF decodes PNG
 * transparency pixel by pixel in PHP, so a 3381x1085 logo alone cost ~2.5 s locally.
 */
class PdfWeightTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    /** A transparent-background PNG with an opaque block, like a real logo. */
    private function png(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 0, 0, intdiv($w, 2), $h, imagecolorallocate($im, 200, 30, 30));
        ob_start(); imagepng($im); return ob_get_clean();
    }

    private function companyWithLogo(string $bytes, string $name = 'logo.png')
    {
        Storage::fake('public');
        Storage::disk('public')->put("logos/{$name}", $bytes);
        $admin = $this->createAdminUser();
        $admin->company->forceFill(['logo' => "logos/{$name}"])->save();
        return $admin;
    }

    private function decode(string $dataUri): array
    {
        [$head, $b64] = explode(',', $dataUri, 2);
        $bytes = base64_decode($b64);
        $info = getimagesizefromstring($bytes);
        return ['bytes' => $bytes, 'w' => $info[0], 'h' => $info[1], 'mime' => $info['mime'], 'head' => $head];
    }

    public function test_an_oversized_logo_is_shrunk_for_pdfs_keeping_shape_and_transparency(): void
    {
        $admin = $this->companyWithLogo($this->png(3381, 1085));

        $img = $this->decode($admin->company->fresh()->image_data_uri);

        $this->assertSame(600, $img['w']);
        $this->assertEqualsWithDelta(1085 * 600 / 3381, $img['h'], 1);   // aspect ratio kept
        $this->assertSame('image/png', $img['mime']);
        $this->assertLessThan(60_000, strlen($img['bytes']));

        // The transparent half is still transparent.
        $im = imagecreatefromstring($img['bytes']);
        $alpha = (imagecolorat($im, $img['w'] - 5, 5) >> 24) & 0x7F;
        $this->assertGreaterThan(100, $alpha, 'transparency was lost');
    }

    public function test_a_small_logo_is_embedded_untouched(): void
    {
        $original = $this->png(300, 100);
        $admin = $this->companyWithLogo($original);

        $img = $this->decode($admin->company->fresh()->image_data_uri);

        $this->assertSame($original, $img['bytes']);
    }

    public function test_a_large_jpeg_is_shrunk_too(): void
    {
        $im = imagecreatetruecolor(2400, 1200);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 200));
        ob_start(); imagejpeg($im, null, 95); $jpeg = ob_get_clean();
        $admin = $this->companyWithLogo($jpeg, 'logo.jpg');

        $img = $this->decode($admin->company->fresh()->image_data_uri);

        $this->assertSame(600, $img['w']);
        $this->assertSame('image/jpeg', $img['mime']);
    }

    public function test_an_undecodable_image_never_breaks_the_pdf(): void
    {
        $admin = $this->companyWithLogo('this is not an image');

        $this->assertIsString($admin->company->fresh()->image_data_uri);   // original passed through, no exception
    }

    public function test_a_repeat_render_serves_the_cached_copy(): void
    {
        $admin = $this->companyWithLogo($this->png(2000, 800));

        $first = $admin->company->fresh()->image_data_uri;
        Storage::disk('public')->put('logos/logo.png', 'overwritten but same mtime second');
        // Same path and mtime second → same cache key → no re-decode of the (now corrupt) file.
        touch(Storage::disk('public')->path('logos/logo.png'), filemtime(Storage::disk('public')->path('logos/logo.png')));

        $this->assertSame($first, $admin->company->fresh()->image_data_uri);
    }

    public function test_font_subsetting_is_on_so_pdfs_do_not_carry_whole_font_files(): void
    {
        $this->assertTrue(config('dompdf.options.enable_font_subsetting'));
    }

    public function test_a_quotation_pdf_with_a_huge_logo_stays_small(): void
    {
        $admin = $this->companyWithLogo($this->png(3381, 1085));
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $panelType = PanelType::factory()->create(['company_id' => $admin->company_id]);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'panel_rows' => [[
                'panel_type_id' => $panelType->id, 'thickness' => 50, 'density_type' => 'PUF', 'density_kgm3' => 40,
                'top_skin_material' => 'PPGL', 'top_skin_thickness' => '0.50', 'top_surface' => 'PLAIN',
                'bottom_skin_material' => 'PPGL', 'bottom_skin_thickness' => '0.50',
                'sizes' => [['length_mm' => 4450, 'nos' => 30, 'rate_per_sqm' => 1075]],
            ]],
        ])->assertStatus(201);

        $pdf = app(QuotationPdfService::class)->generate(Quotation::find($res->json('data.id')))->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertLessThan(400 * 1024, strlen($pdf), 'PDF is back to ~1 MB — fonts or the logo are being embedded whole.');
    }
}
