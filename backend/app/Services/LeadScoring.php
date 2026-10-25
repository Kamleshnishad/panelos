<?php

namespace App\Services;

use App\Models\Lead;
use App\Support\Inr;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How promising an open lead looks (LEADS_PLAN 5.8, audit LP-06): a score from 0 to 100 and a temperature, hot, warm or cold.
 * Nothing is stored: the score is worked out when leads are read, from facts that are already on the lead, so it is right for every existing lead, moves by itself as
 * a lead goes quiet, and can be used to filter and sort. Closed leads (won, lost, invalid) have no score.
 *
 *  - STAGE, up to 40: the stage's chance of winning (Master Data -> Lead stages) times 0.4; a lead on hold gets 0.
 *  - SIZE, up to 20: the estimated value or area. At least Rs 5,00,000 or 1,000 sqm is 20, at least Rs 1,00,000 or 200 sqm is 14, anything entered is 8, nothing is 0.
 *  - ACTIVITY, up to 25: when a person last touched the lead (or when it arrived, if nobody has): within 2 days 25, within 7 days 15, within 14 days 5, longer 0.
 *  - CONTACT, up to 10: a phone number is 6, an e-mail address 4 (a lead nobody can reach is hard to win).
 *  - FOLLOW-UP, 5: a next action or follow-up date today or later; none, or one already overdue, is 0.
 * A lead ON HOLD is parked until its date, so whatever it adds up to it never scores above 34: it is always cold.
 * HOT is 65 or more, WARM 35 to 64, COLD below 35. The numbers are plain constants below so they are easy to read and to change in one place.
 *
 * sql() is the one definition used for filtering, sorting and counting. explain() restates the same rules in words for the lead screen, and a test holds the two equal.
 */
final class LeadScoring
{
    public const HOT = 65;
    public const WARM = 35;
    public const STAGE_WEIGHT = 0.4;
    public const ON_HOLD_CAP = self::WARM - 1;
    public const TEMPERATURES = ['hot', 'warm', 'cold'];
    private const SIZE = [[500000, 1000, 20], [100000, 200, 14]];     // [value at least, sqm at least, points]
    private const SIZE_ANY = 8;
    private const ACTIVITY_HOURS = [48, 168, 336];                       // within these many hours ...
    private const ACTIVITY_POINTS = [25, 15, 5, 0];                      // ... earns these points; older earns the last
    private const PHONE = 6;
    private const EMAIL = 4;
    private const FOLLOW_UP = 5;

    public static function temperature(?int $score): ?string
    {
        return $score === null ? null : ($score >= self::HOT ? 'hot' : ($score >= self::WARM ? 'warm' : 'cold'));
    }

    // ── SQL ──────────────────────────────────────────────────────────────────

    private static function q(string $v): string
    {
        return DB::connection()->getPdo()->quote($v);
    }

    private static function stagePointsSql(int $companyId): string
    {
        $when = '';
        foreach (LeadStages::for($companyId)->rows as $r) {
            $when .= ' when ' . self::q($r['key']) . ' then ' . self::stagePoints((int) $r['probability']);
        }

        return 'case leads.status' . $when . ' else 0 end';                // on hold (and anything unknown) earns nothing for its stage
    }

    private static function stagePoints(int $probability): int
    {
        return (int) round($probability * self::STAGE_WEIGHT);
    }

    private static function sizeSql(): string
    {
        $v = 'coalesce(leads.est_value, 0)';
        $a = 'coalesce(leads.est_qty_sqm, 0)';
        $sql = 'case';
        foreach (self::SIZE as [$value, $sqm, $points]) {
            $sql .= " when {$v} >= {$value} or {$a} >= {$sqm} then {$points}";
        }

        return $sql . " when {$v} > 0 or {$a} > 0 then " . self::SIZE_ANY . ' else 0 end';
    }

    private static function activitySql(): string
    {
        $hours = 'timestampdiff(hour, coalesce((' . LeadEngagement::lastTouchSql() . '), leads.created_at), ' . self::q(now()->toDateTimeString()) . ')';

        return 'elt(interval(' . $hours . ', ' . implode(', ', array_map(fn ($h) => $h + 1, self::ACTIVITY_HOURS)) . ') + 1, ' . implode(', ', self::ACTIVITY_POINTS) . ')';
    }

    private static function contactSql(): string
    {
        return "(case when leads.phone is not null and leads.phone <> '' then " . self::PHONE . " else 0 end + case when leads.email is not null and leads.email <> '' then " . self::EMAIL . ' else 0 end)';
    }

    private static function followUpSql(): string
    {
        return 'case when leads.next_follow_up_date >= ' . self::q(LeadTaskService::today()->toDateString()) . ' then ' . self::FOLLOW_UP . ' else 0 end';
    }

    /** SQL: the score of the lead on this row; NULL for a closed lead. */
    public static function sql(int $companyId): string
    {
        $closed = "'" . implode("','", LeadStages::CLOSED) . "'";

        return "(case when leads.status in ({$closed}) then null else least(" . self::stagePointsSql($companyId) . ' + ' . self::sizeSql() . ' + ' . self::activitySql() . ' + '
            . self::contactSql() . ' + ' . self::followUpSql() . ", case when leads.status = 'on_hold' then " . self::ON_HOLD_CAP . ' else 100 end) end)';
    }

    /** Add `score` to every lead the query returns. */
    public static function select(Builder $q, int $companyId): Builder
    {
        return $q->selectRaw(self::sql($companyId) . ' as score');
    }

    /** The score for one lead already in hand (the detail screen). */
    public static function attach(Lead $lead): Lead
    {
        $row = Lead::withoutGlobalScope('tenant')->where('leads.id', $lead->id)->selectRaw(self::sql((int) $lead->company_id) . ' as score')->first();
        $lead->setAttribute('score', $row?->getRawOriginal('score'));

        return $lead;
    }

    /** Restrict a lead query to one temperature. */
    public static function onlyTemperature(Builder $q, int $companyId, string $temperature): Builder
    {
        $s = self::sql($companyId);

        return match ($temperature) {
            'hot'   => $q->whereRaw("{$s} >= " . self::HOT),
            'warm'  => $q->whereRaw("{$s} >= " . self::WARM)->whereRaw("{$s} < " . self::HOT),
            'cold'  => $q->whereRaw("{$s} < " . self::WARM),
            default => $q,
        };
    }

    /** @return array{hot:int, warm:int, cold:int} how many open leads in the query are in each temperature (on hold counts, closed does not) */
    public static function counts(Builder $visible, int $companyId): array
    {
        $s = self::sql($companyId);
        $row = (clone $visible)->reorder()->selectRaw("coalesce(sum({$s} >= " . self::HOT . "), 0) as hot, coalesce(sum({$s} >= " . self::WARM . " and {$s} < " . self::HOT . "), 0) as warm, coalesce(sum({$s} < " . self::WARM . "), 0) as cold")->first();

        return ['hot' => (int) $row->hot, 'warm' => (int) $row->warm, 'cold' => (int) $row->cold];
    }

    // ── the same rules in words ──────────────────────────────────────────────

    /**
     * The score and what it is made of, for the lead screen. Needs the lead's `last_touch_at` (LeadEngagement::attach); null for a closed lead.
     * @return array{score:int, temperature:string, parts:list<array{key:string,label:string,points:int,max:int,why:string}>}|null
     */
    public static function explain(Lead $lead): ?array
    {
        if (in_array($lead->status, LeadStages::CLOSED, true)) {
            return null;
        }
        $stages = LeadStages::for((int) $lead->company_id);
        $parts = [];

        if ($lead->status === 'on_hold') {
            $parts[] = ['key' => 'stage', 'label' => 'Stage', 'points' => 0, 'max' => 40, 'why' => 'On hold: parked, so it never scores above ' . self::ON_HOLD_CAP];
        } else {
            $p = (int) $stages->probability($lead->status);
            $parts[] = ['key' => 'stage', 'label' => 'Stage', 'points' => self::stagePoints($p), 'max' => 40, 'why' => $stages->name($lead->status) . ", {$p}% chance of winning"];
        }

        $value = (float) ($lead->est_value ?? 0);
        $sqm = (float) ($lead->est_qty_sqm ?? 0);
        $size = 0;
        foreach (self::SIZE as [$v, $a, $points]) {
            if ($value >= $v || $sqm >= $a) { $size = $points; break; }
        }
        if ($size === 0 && ($value > 0 || $sqm > 0)) {
            $size = self::SIZE_ANY;
        }
        $has = array_filter([$value > 0 ? 'Rs ' . Inr::fmt($value, 0) : null, $sqm > 0 ? rtrim(rtrim(sprintf('%.2f', $sqm), '0'), '.') . ' sqm' : null]);
        $parts[] = ['key' => 'size', 'label' => 'Size', 'points' => $size, 'max' => 20, 'why' => $has ? 'Estimated ' . implode(' and ', $has) : 'No value or area entered'];

        $touch = $lead->last_touch_at ?? $lead->created_at;
        $hours = intdiv(max(0, now()->timestamp - $touch->timestamp), 3600);
        $band = 0;
        foreach (self::ACTIVITY_HOURS as $i => $limit) {
            if ($hours <= $limit) { break; }
            $band = $i + 1;
        }
        $days = intdiv($hours, 24);
        $parts[] = ['key' => 'activity', 'label' => 'Activity', 'points' => self::ACTIVITY_POINTS[$band], 'max' => 25,
            'why' => ($lead->last_touch_at ? 'Last touched ' : 'Nobody has touched it since it arrived ') . ($days === 0 ? 'today' : "{$days} day" . ($days === 1 ? '' : 's') . ' ago')];

        $phone = trim((string) $lead->phone) !== '';
        $email = trim((string) $lead->email) !== '';
        $parts[] = ['key' => 'contact', 'label' => 'Contact details', 'points' => ($phone ? self::PHONE : 0) + ($email ? self::EMAIL : 0), 'max' => 10,
            'why' => $phone && $email ? 'Phone and e-mail' : ($phone ? 'Phone only' : ($email ? 'E-mail only' : 'No phone or e-mail'))];

        $planned = $lead->next_follow_up_date && $lead->next_follow_up_date->toDateString() >= LeadTaskService::today()->toDateString();
        $parts[] = ['key' => 'follow_up', 'label' => 'Follow-up', 'points' => $planned ? self::FOLLOW_UP : 0, 'max' => 5,
            'why' => $planned ? 'Next follow-up planned' : ($lead->next_follow_up_date ? 'Follow-up is overdue' : 'No follow-up planned')];

        $score = array_sum(array_column($parts, 'points'));
        if ($lead->status === 'on_hold') {
            $score = min($score, self::ON_HOLD_CAP);
        }

        return ['score' => $score, 'temperature' => self::temperature($score), 'parts' => $parts];
    }
}
