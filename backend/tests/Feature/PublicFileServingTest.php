<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Public-disk files are served via /api/file?path=... — the path travels in
 * the query string because both nginx layers in front of this app (the
 * container vhost and the host VPS proxy) intercept any URI ending in an
 * image extension and 404 it before PHP sees it. That was the actual cause
 * of the broken company-logo image on production; a URI-based route was
 * verified unreachable from outside while working fine inside the container.
 */
class PublicFileServingTest extends TestCase
{
    public function test_serves_an_existing_public_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/1/test.png', UploadedFile::fake()->image('test.png')->getContent());

        $response = $this->get('/api/file?path=logos/1/test.png');

        $response->assertStatus(200);
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
    }

    /**
     * Storage::url() concatenates "<base>/<path>", so with the configured
     * base ending in "?path=" every generated URL arrives with a leading
     * slash in the query value. That must still resolve.
     * (Checked against the real config — Storage::fake() swaps in its own url.)
     */
    public function test_handles_the_leading_slash_storage_url_produces(): void
    {
        $this->assertStringEndsWith('/api/file?path=', config('filesystems.disks.public.url'));

        Storage::fake('public');
        Storage::disk('public')->put('logos/1/test.png', UploadedFile::fake()->image('test.png')->getContent());

        $this->get('/api/file?path=/logos/1/test.png')->assertStatus(200);
    }

    public function test_404s_a_missing_file(): void
    {
        Storage::fake('public');

        $this->get('/api/file?path=logos/1/does-not-exist.png')->assertStatus(404);
    }

    public function test_404s_a_path_traversal_attempt(): void
    {
        Storage::fake('public');

        $this->get('/api/file?path=../../.env')->assertStatus(404);
    }

    public function test_404s_an_empty_path(): void
    {
        Storage::fake('public');

        $this->get('/api/file')->assertStatus(404);
    }
}
