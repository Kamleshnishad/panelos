<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Serves files from the public disk under /api/file?path=...
 *
 * Two layers of nginx stand between a browser and these files, and both
 * intercept any URL whose PATH ends in an image extension, serving it from
 * their own document root and 404ing before the request reaches PHP:
 *
 *   - the backend container's own vhost (fixed there with a ^~ /api/ block)
 *   - the host VPS proxy in front of every app on the box, which we don't
 *     control from this repo
 *
 * Hence the file path travels in the QUERY STRING, never the path: nginx's
 * `location ~* \.(png|jpg|...)$` matches the URI path only, so /api/file is
 * invisible to it regardless of what is being served.
 */
class PublicFileController extends Controller
{
    public function show(Request $request)
    {
        // Storage's url() builds this as "<base>/<path>", so the value
        // arrives with a leading slash — see config/filesystems.php.
        $path = ltrim((string) $request->query('path'), '/');

        // Storage::path() would resolve '..' segments, so guard before
        // touching the filesystem at all.
        if ($path === '' || str_contains($path, '..')) {
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
