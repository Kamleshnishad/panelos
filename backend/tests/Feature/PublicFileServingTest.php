<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The host's reverse proxy only forwards /api/* to the backend container —
 * a direct /storage/{path} request never reaches it (confirmed while
 * diagnosing a broken company-logo image on production). Files on the
 * public disk are served through /api/storage/{path} instead; this locks
 * in that route and its path-traversal guard.
 */
class PublicFileServingTest extends TestCase
{
    public function test_serves_an_existing_public_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/1/test.png', UploadedFile::fake()->image('test.png')->getContent());

        $response = $this->get('/api/storage/logos/1/test.png');

        $response->assertStatus(200);
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
    }

    public function test_404s_a_missing_file(): void
    {
        Storage::fake('public');

        $this->get('/api/storage/logos/1/does-not-exist.png')->assertStatus(404);
    }

    public function test_404s_a_path_traversal_attempt(): void
    {
        Storage::fake('public');

        $this->get('/api/storage/%2e%2e%2f%2e%2e%2f.env')->assertStatus(404);
    }
}
