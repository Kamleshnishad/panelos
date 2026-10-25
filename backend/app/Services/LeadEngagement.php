<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\NotificationSetting;
use Illuminate\Database\Eloquent\Builder;

/**
 * How a lead is being looked after (LEADS_PLAN 5.6): how fast somebody first responded, and which open leads have gone quiet.
 * Nothing is stored for this: both facts are read from the lead's timeline, so they are right for every lead that already exists and cannot drift.
 *
 *  - A RESPONSE is something a person did, not the system: a call, an e-mail, a WhatsApp chat or a meeting logged by a user, or the user moving the lead
 *    out of "new". A note, a planned action, the lead arriving or the system's own lines do not count.
 *  - FIRST RESPONSE = the earliest response; its time is measured from the moment the lead arrived (when the buyer's own time, for IndiaMART, is within
 *    6 hours of it entering PanelOS; an old enquiry that was imported counts from the import).
 *  - A TOUCH is anything a person did on the lead (any activity with a user, including notes). QUIET = an open lead (new, contacted, qualified, quoted; not
 *    on hold, not closed) with no touch for N days (default 7, Settings -> Notifications) and no next action planned for today or later.
 */
class LeadEngagement
{
    public const OPEN = Lead::OPEN;
    public const DEFAULT_STALE_DAYS = 7;
    public const WITHIN = 60;                           // minutes: "answered within the hour"

    private const CONTACT = "'call','email','whatsapp','meeting'";

    /** SQL: the earliest response on the lead (as a subquery, for a column named `leads`.`id`). */
    public static function firstResponseSql(): string
    {
        return "select min(a.activity_date) from lead_activities a where a.lead_id = leads.id and a.deleted_at is null and a.user_id is not null "
            . "and (a.type in (" . self::CONTACT . ") or (a.type = 'status_change' and a.description like 'Status: new →%'))";
    }

    /** SQL: the latest thing a person did on the lead. */
    public static function lastTouchSql(): string
    {
        return "select max(a.activity_date) from lead_activities a where a.lead_id = leads.id and a.deleted_at is null and a.user_id is not null";
    }

    /** Add `first_response_at` and `last_touch_at` to every lead the query returns. */
    public static function select(Builder $q): Builder
    {
        return $q->select('leads.*')
            ->selectRaw('(' . self::firstResponseSql() . ') as first_response_at')
            ->selectRaw('(' . self::lastTouchSql() . ') as last_touch_at');
    }

    /** The same two facts for one lead already in hand (the detail screen). */
    public static function attach(Lead $lead): Lead
    {
        $row = Lead::withoutGlobalScope('tenant')->where('leads.id', $lead->id)->selectRaw('(' . self::firstResponseSql() . ') as first_response_at')
            ->selectRaw('(' . self::lastTouchSql() . ') as last_touch_at')->first();
        $lead->setAttribute('first_response_at', $row?->getRawOriginal('first_response_at'));
        $lead->setAttribute('last_touch_at', $row?->getRawOriginal('last_touch_at'));

        return $lead;
    }

    /** Quiet days for this company: the setting, or 7 when it is off (0) or never set; the Quiet leads view works either way. */
    public static function staleDays(int $companyId): int
    {
        $d = (int) (NotificationSetting::withoutGlobalScope('tenant')->where('company_id', $companyId)->value('lead_stale_days') ?? self::DEFAULT_STALE_DAYS);

        return $d > 0 ? $d : self::DEFAULT_STALE_DAYS;
    }

    /** Is the bell alert on? (0 days = off; a company with no setting row is on.) */
    public static function alertsOn(int $companyId): bool
    {
        $v = NotificationSetting::withoutGlobalScope('tenant')->where('company_id', $companyId)->value('lead_stale_days');

        return $v === null || (int) $v > 0;
    }

    /** Restrict a lead query to the quiet ones. */
    public static function onlyQuiet(Builder $q, int $companyId): Builder
    {
        $inList = "'" . implode("','", self::OPEN) . "'";

        return $q->whereRaw("leads.status in ({$inList})")
            ->whereRaw('coalesce((' . self::lastTouchSql() . '), leads.created_at) < ?', [now()->subDays(self::staleDays($companyId))->toDateTimeString()])
            ->whereRaw("not exists (select 1 from lead_tasks t where t.lead_id = leads.id and t.status = 'open' and t.deleted_at is null and t.due_date >= ?)", [LeadTaskService::today()->toDateString()]);
    }

    /** Restrict a lead query to leads nobody has responded to yet. */
    public static function onlyUnanswered(Builder $q): Builder
    {
        return $q->whereRaw('not exists (select 1 from lead_activities a where a.lead_id = leads.id and a.deleted_at is null and a.user_id is not null '
            . "and (a.type in (" . self::CONTACT . ") or (a.type = 'status_change' and a.description like 'Status: new →%')))");
    }

    /**
     * The insight block (last 30 days of new leads): how many were answered, how fast, how many within the hour; and right now how many are waiting and how many are quiet.
     * @return array{responded:int, avg_minutes:?int, within_hour_pct:?float, waiting:int, quiet:int, quiet_days:int}
     */
    public static function summary(Builder $visible, int $companyId): array
    {
        $recent = self::select((clone $visible)->where('leads.created_at', '>=', now()->subDays(30))->where('leads.status', '!=', 'invalid'))->limit(5000)->get();
        $mins = $recent->map->response_minutes->filter(fn ($m) => $m !== null);

        return [
            'responded'       => $mins->count(),
            'avg_minutes'     => $mins->isEmpty() ? null : (int) round($mins->avg()),
            'within_hour_pct' => $mins->isEmpty() ? null : round($mins->filter(fn ($m) => $m <= self::WITHIN)->count() / $mins->count() * 100, 1),
            'waiting'         => self::onlyUnanswered((clone $visible)->whereIn('leads.status', self::OPEN))->count(),
            'quiet'           => self::onlyQuiet(clone $visible, $companyId)->count(),
            'quiet_days'      => self::staleDays($companyId),
        ];
    }
}
