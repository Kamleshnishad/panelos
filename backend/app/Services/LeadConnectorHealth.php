<?php

namespace App\Services;

use App\Models\LeadConnector;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * LEADS_PLAN 3.3 (LC-12): is the IndiaMART link actually bringing enquiries in? A quiet failure here means leads go missing with nobody the wiser.
 *
 * One verdict per connector, worst first:
 *  - key_rejected: IndiaMART refused the key (replaced, cancelled or expired). Needs a person to save a new one.
 *  - stale: switched on but no successful pull for an hour. Covers IndiaMART being down, a job that stopped, and anything else.
 *  - idle_soon: IndiaMART stops a key after 7 days without use; warns from day 5. Applies to a saved key that is switched off or never tried.
 *  - ok / waiting (switched on, first pull not done yet) / off (a key, switched off, used recently) / not_set_up.
 *
 * `sync` turns a verdict into one standing bell entry for the company admins (see AppNotificationService::syncStanding), so it repeats
 * nothing and clears itself once fixed. Messages never carry "N hours ago", so the entry only turns unread when the situation changes.
 */
class LeadConnectorHealth
{
    public const TYPE = 'lead_connector_health';
    public const STALE_AFTER_MINUTES = 60;
    public const IDLE_WARN_DAYS = 5;
    public const IDLE_EXPIRY_DAYS = 7;

    public function __construct(private AppNotificationService $notify)
    {
    }

    public static function assess(LeadConnector $c, ?Carbon $now = null): array
    {
        $now ??= now();
        if (!$c->key()) {
            return self::verdict('not_set_up');
        }

        // Last time IndiaMART answered a request of ours properly (a pull, or a test that worked); the key's age if it never has.
        $use = collect([$c->last_success_at, $c->last_test_ok ? $c->last_test_at : null])->filter()->max()
            ?? $c->key_saved_at ?? $c->created_at ?? $now;
        $daysIdle = (int) $use->diffInDays($now);
        $stops = $use->copy()->addDays(self::IDLE_EXPIRY_DAYS);
        $expiry = $daysIdle >= self::IDLE_WARN_DAYS
            ? ($stops->isPast() ? ' IndiaMART may already have stopped this key.' : ' IndiaMART stops a key that goes unused for 7 days, so this one stops on ' . self::day($stops) . '.')
            : '';

        if ($c->is_enabled) {
            if ($c->last_error_at && $c->last_error_kind === 'bad_key') {
                return self::verdict('key_rejected', 'danger', 'IndiaMART did not accept the key',
                    'No enquiries are coming in. Make a new key in IndiaMART (Lead Manager → CRM Integration) and save it under Master Data → Lead intake.');
            }
            $since = $c->last_success_at ?? $c->enabled_at ?? $c->updated_at ?? $now;
            if ($since->lt($now->copy()->subMinutes(self::STALE_AFTER_MINUTES))) {
                $last = $c->last_success_at ? 'The last good pull was ' . self::at($c->last_success_at) . '.' : 'It has not pulled successfully since it was switched on.';

                return self::verdict('stale', 'danger', 'No IndiaMART enquiries pulled for over an hour',
                    $last . ($c->last_error ? " Last problem: {$c->last_error}" : '') . $expiry);
            }

            return self::verdict($c->last_success_at ? 'ok' : 'waiting');
        }

        if ($expiry !== '') {
            return self::verdict('idle_soon', 'warning', 'The IndiaMART key is about to go idle',
                'It has not been used since ' . self::at($use) . '.' . $expiry . ' Test the connection, or switch the import on, to keep it alive.');
        }

        return self::verdict('off');
    }

    /** What /api/health reports: OK, STALE (a switched-on connector has not even been asked in an hour: the job is not running), or NONE. */
    public static function pullJob(?Carbon $now = null): string
    {
        $now ??= now();
        $on = app(IndiaMartPullService::class)->due();
        if ($on->isEmpty()) {
            return 'NONE';
        }
        $limit = $now->copy()->subMinutes(self::STALE_AFTER_MINUTES);

        return $on->contains(fn (LeadConnector $c) => ($c->last_request_at ?? $c->enabled_at ?? $c->updated_at)?->lt($limit)) ? 'STALE' : 'OK';
    }

    public function sync(int $companyId): void
    {
        $c = LeadConnector::withoutGlobalScope('tenant')->where('company_id', $companyId)->where('type', LeadConnector::INDIAMART)->first();
        $v = $c ? self::assess($c) : null;
        $items = [];
        if ($v && $v['severity']) {
            $items[LeadConnector::INDIAMART] = ['title' => $v['title'], 'body' => $v['body'], 'section' => 'master', 'severity' => $v['severity']];
        }
        $admins = User::withoutGlobalScope('tenant')->where('company_id', $companyId)->where('is_active', true)->where('is_company_admin', true)->pluck('id')->all();
        $this->notify->syncStanding($companyId, $admins, self::TYPE, $items);
    }

    private static function verdict(string $state, ?string $severity = null, string $title = '', string $body = ''): array
    {
        return ['state' => $state, 'severity' => $severity, 'title' => $title, 'body' => $body];
    }

    private static function at(Carbon $t): string
    {
        return $t->copy()->timezone('Asia/Kolkata')->format('j M, H:i');
    }

    private static function day(Carbon $t): string
    {
        return $t->copy()->timezone('Asia/Kolkata')->format('j M');
    }
}
