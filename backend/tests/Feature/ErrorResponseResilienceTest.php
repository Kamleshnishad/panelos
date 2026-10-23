<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * PB-042. On production a server error reached the browser as an EMPTY 500, and
 * nothing was logged.
 *
 * The app has a catch-all handler that logs the exception and returns a
 * SERVER_ERROR envelope with a support reference. But storage/logs/laravel.log
 * had been created by a root `docker exec php artisan ...` (mode 0644), while the
 * web workers run as the unprivileged `panelos` user — so they could not append
 * to it. Log::error() then threw INSIDE the error handler, and the response died:
 * no envelope, no log line, no reason.
 *
 * A failing log write must never take the response down with it.
 */
class ErrorResponseResilienceTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function boom(): void
    {
        // Production behaviour: debug off, so the catch-all builds the envelope.
        config(['app.debug' => false]);

        // A log file the process cannot open — the production failure, reproduced.
        config(['logging.channels.single.path' => 'Z:/definitely/not/a/real/dir/laravel.log']);

        Route::middleware('api')->get('/api/zz-boom', fn () => throw new \RuntimeException('simulated failure'));
    }

    public function test_a_server_error_still_returns_a_readable_envelope_when_the_log_cannot_be_written(): void
    {
        $this->boom();
        $admin = $this->createAdminUser();

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/zz-boom');

        $res->assertStatus(500);
        $this->assertNotSame('', trim($res->getContent()), 'The 500 had an empty body — the PB-042 failure.');
        $res->assertJsonPath('error_code', 'SERVER_ERROR');
        $this->assertNotEmpty($res->json('reference'), 'No support reference was returned.');
    }

    public function test_the_log_channel_is_configured_so_a_root_created_file_stays_writable(): void
    {
        // Files created by a root artisan run must not lock the web workers out.
        $this->assertSame(0666, config('logging.channels.single.permission'));
        $this->assertTrue(config('logging.channels.stack.ignore_exceptions'));
    }
}
