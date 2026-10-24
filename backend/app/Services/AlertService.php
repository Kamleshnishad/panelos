<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\MailStatus;
use App\Support\SystemHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the operator when the server throws an error nobody saw (EN-03). It runs inside the exception handler, so
 * it must never throw, never block for long, and never turn one error into a flood: one e-mail per quarter hour.
 */
class AlertService
{
    public const THROTTLE_MINUTES = 15;

    public static function serverError(\Throwable $e): void
    {
        try {
            SystemHealth::recordError();

            if (!PlatformSetting::current()->alerts_enabled || !MailStatus::describe()['delivers']) {
                return;
            }
            if (!Cache::add('alert:server-error', 1, now()->addMinutes(self::THROTTLE_MINUTES))) {
                return;
            }

            $req = request();
            self::send(
                'PanelOS server error: ' . class_basename($e),
                implode("\n", [
                    'A server error was not handled by the app.',
                    '',
                    'Error:   ' . $e->getMessage(),
                    'Where:   ' . basename($e->getFile()) . ':' . $e->getLine(),
                    'Request: ' . ($req ? $req->method() . ' ' . $req->path() : 'n/a (console/queue)'),
                    'Time:    ' . now()->toDateTimeString(),
                    '',
                    'The full trace is in the server log. Further errors in the next ' . self::THROTTLE_MINUTES . ' minutes are not e-mailed.',
                    'Turn these alerts off in Super Admin -> Settings.',
                ]),
            );
        } catch (\Throwable) {
            // An alert that fails must not become a second error.
        }
    }

    /** Where alerts go: the saved address, else every super admin's own e-mail. */
    public static function recipients(): array
    {
        $saved = trim((string) PlatformSetting::current()->alert_email);
        if ($saved !== '') {
            return [$saved];
        }

        return User::withoutGlobalScope('tenant')->where('is_super_admin', true)->pluck('email')->filter()->unique()->values()->all();
    }

    /** Sends one message to the recipients. Throws on a mail failure so the Settings test can show the reason. */
    public static function send(string $subject, string $body): void
    {
        $to = self::recipients();
        if (!$to) {
            throw new \RuntimeException('No alert address is set and there is no super admin e-mail to fall back on.');
        }
        Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
    }
}
