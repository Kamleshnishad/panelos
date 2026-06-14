<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Provides a base64 data-URI for an image stored on the `public` disk.
 * Using a data-URI keeps DomPDF bulletproof (no remote/local path resolution).
 * Each model defines $imageField (the column holding the relative path).
 */
trait HasImageDataUri
{
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
            return 'data:' . $mime . ';base64,' . base64_encode($disk->get($path));
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
}
