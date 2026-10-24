<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What /api/health reports and what the uptime monitor reads. Two silent failures matter most here: the scheduler
 * stopping (no backups, no reminders — PB-030) and the app throwing 500s while the database is fine.
 */
class SystemHealth
{
    /** The scheduler beats once a minute; this many seconds without one means it is not running. */
    public const SCHEDULER_STALE_AFTER = 300;

    /** This many server errors inside one hour marks the system degraded. */
    public const ERROR_SPIKE = 25;

    public static function heartbeat(): void
    {
        Cache::put('health:scheduler', now()->timestamp, 86400);
    }

    public static function recordError(): void
    {
        $key = 'health:errors:' . now()->format('YmdH');
        Cache::add($key, 0, 7200);
        Cache::increment($key);
    }

    public static function check(): array
    {
        $db = 'OK';
        try {
            DB::select('select 1');
        } catch (\Throwable) {
            $db = 'DOWN';
        }

        // No beat yet = just deployed, the first one lands within a minute: unknown is not a failure.
        $beat = self::safe(fn () => Cache::get('health:scheduler'));
        $scheduler = $beat === null ? 'UNKNOWN' : (now()->timestamp - (int) $beat > self::SCHEDULER_STALE_AFTER ? 'STALE' : 'OK');

        $errors = (int) self::safe(fn () => Cache::get('health:errors:' . now()->format('YmdH'), 0));
        $failedJobs = (int) self::safe(fn () => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0);

        $ok = $db === 'OK' && $scheduler !== 'STALE' && $errors < self::ERROR_SPIKE;

        return [
            'status'           => $ok ? 'OK' : 'DEGRADED',
            'database'         => $db,
            'scheduler'        => $scheduler,
            'errors_last_hour' => $errors,
            'failed_jobs'      => $failedJobs,
            'timestamp'        => now(),
        ];
    }

    private static function safe(callable $f): mixed
    {
        try {
            return $f();
        } catch (\Throwable) {
            return null;
        }
    }
}
