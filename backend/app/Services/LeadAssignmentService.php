<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadAssignmentRule;
use App\Models\User;

/**
 * Who gets a new lead, and telling them at once (LEADS_PLAN 2.4).
 *
 *  - assignByRules(): for a new lead nobody was chosen for. The first active rule (by priority) whose conditions match and that has someone
 *    able to take it wins; the people in it take turns. No rules (the default) or no match: the lead stays unassigned, as before.
 *  - announce(): every assignment, by rule or by hand, writes a timeline line and rings the bell of the NEW assignee only (never the previous
 *    one, never the person who made the change). WhatsApp alerts are not built (they wait for the client's answer).
 *
 * Call assignByRules() inside the transaction that creates the lead: it locks the rules so two leads arriving together take different turns.
 */
class LeadAssignmentService
{
    /** While an import runs the bell is held: the people who were given leads are counted here, and the import sends each of them one line. */
    private static ?array $held = null;

    public function __construct(private AppNotificationService $notify)
    {
    }

    /** Run $f with the per-lead bell held back. @return array<int,int> user id => how many leads they were given meanwhile */
    public static function holdAlerts(callable $f): array
    {
        self::$held = [];
        try {
            $f();

            return self::$held;
        } finally {
            self::$held = null;
        }
    }

    /** @return ?int the user the lead was given to */
    public function assignByRules(Lead $lead): ?int
    {
        $rules = LeadAssignmentRule::withoutGlobalScope('tenant')->where('company_id', $lead->company_id)->where('is_active', true)
            ->orderBy('priority')->orderBy('id')->lockForUpdate()->get();

        foreach ($rules as $rule) {
            if (!$rule->matches($lead->source, $lead->city, $lead->application)) {
                continue;
            }
            $userId = $this->nextPerson($rule);
            if ($userId === null) {
                continue;                                        // nobody in this rule can take it: try the next rule
            }
            $rule->update(['last_user_id' => $userId]);
            $lead->update(['assigned_to_user_id' => $userId]);
            $this->announce($lead, null, $userId, "rule: {$rule->name}");

            return $userId;
        }

        return null;
    }

    /** The next able person after the last one served, in the order the rule lists them, wrapping round. */
    private function nextPerson(LeadAssignmentRule $rule): ?int
    {
        $pool = array_values(array_unique(array_map('intval', $rule->user_ids ?? [])));
        $able = User::withoutGlobalScope('tenant')->where('company_id', $rule->company_id)->where('is_active', true)->whereIn('id', $pool)->pluck('id')->map(fn ($i) => (int) $i)->all();
        if (!$able) {
            return null;
        }
        $at = array_search((int) $rule->last_user_id, $pool, true);
        for ($step = 1; $step <= count($pool); $step++) {
            $candidate = $pool[(($at === false ? -1 : $at) + $step) % count($pool)];
            if (in_array($candidate, $able, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Record that a lead's assignee changed and tell the new one. $how is a few words: "rule: Website leads", "by Asha", "by hand".
     * Nothing happens when nothing changed. The bell goes only to the new assignee, and not to the person who made the change.
     */
    public function announce(Lead $lead, ?int $fromId, ?int $toId, string $how, ?int $actorId = null): void
    {
        if ($fromId === $toId) {
            return;
        }
        $names = User::withoutGlobalScope('tenant')->whereIn('id', array_filter([$fromId, $toId]))->pluck('name', 'id');
        $text = $toId === null
            ? "Unassigned (was {$names[$fromId]}, {$how})"
            : ($fromId === null ? "Assigned to {$names[$toId]} ({$how})" : "Reassigned: {$names[$fromId]} → {$names[$toId]} ({$how})");

        LeadActivity::create(['company_id' => $lead->company_id, 'lead_id' => $lead->id, 'user_id' => $actorId, 'type' => 'status_change', 'description' => $text, 'activity_date' => now()]);

        if ($toId !== null && $toId !== $actorId) {
            $this->alert($lead, $toId, $fromId === null ? 'New lead' : 'Lead assigned to you');
        }
    }

    /** One bell entry for one person: who, where from, what they want, how to reach them. */
    public function alert(Lead $lead, int $userId, string $headline): void
    {
        if (self::$held !== null) {
            self::$held[$userId] = (self::$held[$userId] ?? 0) + 1;

            return;
        }
        $bits = array_filter([$lead->source, $lead->city, $lead->application]);
        $body = implode(' · ', $bits)
            . ($lead->requirement ? '. ' . mb_substr($lead->requirement, 0, 120) : '')
            . ($lead->phone ? '. Phone ' . $lead->phone : '');

        $this->notify->notify($lead->company_id, [$userId], 'lead_assigned', "{$headline}: {$lead->lead_no} {$lead->contact_name}", trim($body, ' .') ?: null, 'leads', $lead->id, 'info');
    }
}
