<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Provides a base64 data-URI for an image stored on the `public` disk.
 * Using a data-URI keeps DomPDF bulletproof (no remote/local path resolution).
 * Each model defines $imageField (the column holding the relative path).
 */
trait HasImageDataUri
{
    /**
     * Longest side, in pixels, an image keeps when it goes into a PDF. A logo is
     * shown ~220 px wide, so this is still crisp when printed. Larger uploads are
     * shrunk: DomPDF decodes PNG transparency pixel by pixel in PHP, and a
     * 3381x1085 logo made every PDF take 3 s locally (8-12 s on the server) and
     * weigh ~0.8 MB for one picture (PB-022).
     */
    private const PDF_IMAGE_MAX_PX = 600;

    private function imagePath(): ?string
    {
        $field = property_exists($this, 'imageField') ? $this->imageField : 'image';
        return $this->{$field} ?? null;
    }

    /** Heavy: base64 data-URI for embedding in PDFs. Never appended automatically. */
    public function getImageDataUriAttribute(): ?string
    {
        // DomPDF needs the GD extension to rasterise embedded images.
        // Without it, return null so PDFs degrade gracefully (never error).
        if (!extension_loaded('gd')) return null;

        $path = $this->imagePath();
        if (!$path) return null;
        try {
            $disk = Storage::disk('public');
            if (!$disk->exists($path)) return null;
            $mime = $disk->mimeType($path) ?: 'image/png';
            $build = fn () => self::buildPdfDataUri($disk->get($path), $mime);

            // The shrunk image is cached against the file's modified time, so a
            // replaced logo is picked up and the resize is paid once, not per PDF.
            try {
                return Cache::remember('pdf-img:' . md5($path) . ':' . $disk->lastModified($path) . ':' . self::PDF_IMAGE_MAX_PX,
                    now()->addDays(30), $build);
            } catch (\Throwable) {
                return $build();   // a cache store that is down must not break a PDF
            }
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Lightweight: public URL for UI thumbnails. Safe to append. */
    public function getImageUrlAttribute(): ?string
    {
        $path = $this->imagePath();
        return $path ? Storage::disk('public')->url($path) : null;
    }

    private static function buildPdfDataUri(string $bytes, string $mime): string
    {
        [$bytes, $mime] = self::shrinkForPdf($bytes, $mime);
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /**
     * @return array{0:string,1:string} [bytes, mime] — the original whenever the image
     *         is already small, is not PNG/JPEG, or cannot be decoded.
     */
    private static function shrinkForPdf(string $bytes, string $mime): array
    {
        $info = @getimagesizefromstring($bytes);
        if (!$info || max($info[0], $info[1]) <= self::PDF_IMAGE_MAX_PX) return [$bytes, $mime];

        $isPng = $info[2] === IMAGETYPE_PNG;
        if (!$isPng && $info[2] !== IMAGETYPE_JPEG) return [$bytes, $mime];

        $src = @imagecreatefromstring($bytes);
        if (!$src) return [$bytes, $mime];

        $scale = self::PDF_IMAGE_MAX_PX / max($info[0], $info[1]);
        $w = max(1, (int) round($info[0] * $scale));
        $h = max(1, (int) round($info[1] * $scale));

        $dst = imagecreatetruecolor($w, $h);
        if ($isPng) {   // keep transparency
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);

        ob_start();
        $isPng ? imagepng($dst, null, 9) : imagejpeg($dst, null, 85);
        $out = ob_get_clean();

        return $out ? [$out, $isPng ? 'image/png' : 'image/jpeg'] : [$bytes, $mime];
    }
}
