<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * Serves files from the public disk under /api/storage/{path}.
 *
 * The host's reverse proxy only forwards /api/* to this container (confirmed
 * by diagnosing a broken company-logo image: the container's own nginx
 * served /storage/{path} fine, but the request never reached it — the host
 * proxy 404'd it before it got here). Mounting the same files under /api
 * sidesteps that without needing proxy config we don't control.
 */
class PublicFileController extends Controller
{
    public function show(string $path)
    {
        // Storage::disk('public')->path() resolves '..' segments, so guard
        // explicitly before touching the filesystem at all.
        if (str_contains($path, '..')) {
            abort(404);
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, null, [
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
