<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadAttachment;
use App\Models\LeadImportLog;
use App\Models\LeadPayload;
use App\Models\LeadRequirementLine;
use App\Models\LeadTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Merging two leads for the same buyer (LEADS_PLAN 5.8, audit LP-08): the lead you are looking at is KEPT and the other one is folded into it.
 *
 *  - What moves to the kept lead: the whole timeline, the next actions, the files, the original messages, the requirement lines (up to the 30 a lead may have) and the
 *    import-log rows. Blanks on the kept lead are filled from the other (phone, e-mail, company, city, product, area, value, owner, customer); what the kept lead already
 *    has is never overwritten. The two requirement texts are joined. The earlier first-seen time wins, and an open lead keeps the more advanced of the two open stages.
 *  - What stops a merge, in words: either lead is closed (won, lost, invalid: reopen it first); both already have a quotation (the automatic "won" follows one quotation
 *    per lead); the two are linked to different customers.
 *  - The other lead is soft-deleted and points at the kept one (`merged_into_lead_id`). Because a deleted lead still holds its unique keys, the same IndiaMART enquiry or
 *    website submission cannot create it again.
 *  - It cannot be undone, so the screen asks first. One line on the kept lead's timeline says what was merged and what moved.
 */
class LeadMergeService
{
    private const FILL = ['company_name', 'phone', 'email', 'city', 'application', 'est_qty_sqm', 'est_value', 'assigned_to_user_id', 'customer_id', 'quotation_id', 'campaign', 'landing_page',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    public function __construct(private LeadService $leads, private LeadTaskService $tasks, private LeadMatcher $matcher)
    {
    }

    /** Why these two cannot be merged, or null when they can. */
    public function problem(Lead $keep, Lead $dup): ?string
    {
        if ($keep->id === $dup->id) {
            return 'A lead cannot be merged into itself.';
        }
        if ($keep->company_id !== $dup->company_id) {
            return 'These leads are not in the same company.';
        }
        foreach ([$keep, $dup] as $l) {
            if ($l->isClosed()) {
                return "{$l->lead_no} is {$l->status}. Reopen it first, or merge into a lead that is still open.";
            }
        }
        if ($keep->quotation_id && $dup->quotation_id) {
            return 'Both leads already have a quotation, and a lead follows one quotation. Merge only when at most one of them has been quoted.';
        }
        if ($keep->customer_id && $dup->customer_id && $keep->customer_id !== $dup->customer_id) {
            return 'The two leads are linked to different customers.';
        }

        return null;
    }

    /**
     * Leads that could be merged into this one: the same buyer first (same phone or e-mail), then any open lead matching $search; only open or on-hold leads this person may see.
     * @return list<array<string,mixed>>
     */
    public function candidates(Lead $lead, ?User $viewer, ?string $search = null): array
    {
        $visible = fn () => $this->leads->visible((int) $lead->company_id, $viewer)->where('leads.id', '!=', $lead->id)->whereNotIn('leads.status', Lead::CLOSED);
        $found = [];

        $ids = collect($this->matcher->matches((int) $lead->company_id, $lead->phone, $lead->email, $lead->id))->pluck('matched_on', 'id');
        if ($ids->isNotEmpty()) {
            foreach ($visible()->whereIn('leads.id', $ids->keys())->orderByDesc('leads.id')->get() as $l) {
                $found[$l->id] = ['why' => 'same ' . implode(' and ', $ids[$l->id]), 'lead' => $l];
            }
        }
        $s = trim((string) $search);
        if ($s !== '') {
            $like = '%' . addcslashes($s, '\\%_') . '%';
            foreach ($visible()->where(fn ($q) => $q->where('lead_no', 'like', $like)->orWhere('contact_name', 'like', $like)->orWhere('company_name', 'like', $like)->orWhere('phone', 'like', $like))
                ->orderByDesc('leads.id')->limit(10)->get() as $l) {
                $found[$l->id] ??= ['why' => 'found by your search', 'lead' => $l];
            }
        }

        return collect($found)->map(fn ($f) => [
            'id' => $f['lead']->id, 'lead_no' => $f['lead']->lead_no, 'contact_name' => $f['lead']->contact_name, 'company_name' => $f['lead']->company_name, 'phone' => $f['lead']->phone,
            'status' => $f['lead']->status, 'why' => $f['why'], 'blocked' => $this->problem($lead, $f['lead']),
        ])->values()->all();
    }

    /**
     * Fold $dup into $keep. @return array{kept:string, merged:string, activities:int, tasks:int, files:int, messages:int, lines:int}
     * @throws ValidationException with the reason when the two cannot be merged
     */
    public function merge(Lead $keep, Lead $dup, ?User $actor): array
    {
        return DB::transaction(function () use ($keep, $dup, $actor) {
            // Lock both in a fixed order so two people merging at once cannot deadlock.
            $locked = Lead::withoutGlobalScope('tenant')->where('company_id', $keep->company_id)->whereIn('id', [$keep->id, $dup->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $keep = $locked->get($keep->id);
            $dup = $locked->get($dup->id);
            if (!$keep || !$dup) {
                $this->refuse('lead', 'One of the leads is no longer there.');
            }
            if ($problem = $this->problem($keep, $dup)) {
                $this->refuse('lead', $problem);
            }

            $this->fill($keep, $dup);

            $lead = ['lead_id' => $keep->id];
            $moved = [
                'activities' => LeadActivity::withoutGlobalScope('tenant')->where('lead_id', $dup->id)->update($lead),
                'tasks'      => LeadTask::withoutGlobalScope('tenant')->where('lead_id', $dup->id)->update($lead),
                'files'      => LeadAttachment::withoutGlobalScope('tenant')->where('lead_id', $dup->id)->update($lead),
                'messages'   => LeadPayload::withoutGlobalScope('tenant')->where('lead_id', $dup->id)->update($lead),
                'lines'      => $this->moveLines($keep, $dup),
            ];
            LeadImportLog::withoutGlobalScope('tenant')->where('lead_id', $dup->id)->update($lead);

            if (LeadTask::withoutGlobalScope('tenant')->where('lead_id', $keep->id)->where('status', LeadTask::OPEN)->exists()) {
                $this->tasks->refreshDate($keep->fresh());                  // the kept lead's date follows its (now larger) set of open actions
            }

            $dup->forceFill(['merged_into_lead_id' => $keep->id])->save();
            $dup->delete();                                                  // soft: it keeps holding its number and its channel reference

            $by = $actor ? " by {$actor->name}" : '';
            LeadActivity::create(['company_id' => $keep->company_id, 'lead_id' => $keep->id, 'user_id' => $actor?->id, 'type' => 'note', 'activity_date' => now(),
                'description' => "Merged {$dup->lead_no} ({$dup->contact_name}) into this lead{$by}. Moved {$moved['activities']} timeline entr" . ($moved['activities'] === 1 ? 'y' : 'ies')
                    . ", {$moved['tasks']} action" . ($moved['tasks'] === 1 ? '' : 's') . ", {$moved['files']} file" . ($moved['files'] === 1 ? '' : 's')
                    . ", {$moved['messages']} original message" . ($moved['messages'] === 1 ? '' : 's') . ". It had come in from {$dup->source}" . ($dup->external_id ? " (ref {$dup->external_id})" : '') . '.']);

            return ['kept' => $keep->lead_no, 'merged' => $dup->lead_no] + $moved;
        });
    }

    /** Blanks on the kept lead are filled from the other; the requirement texts are joined; the earlier arrival and the more advanced open stage win. */
    private function fill(Lead $keep, Lead $dup): void
    {
        $set = [];
        foreach (self::FILL as $f) {
            if (($keep->{$f} === null || $keep->{$f} === '') && $dup->{$f} !== null && $dup->{$f} !== '') {
                $set[$f] = $dup->{$f};
            }
        }
        $a = trim((string) $keep->requirement);
        $b = trim((string) $dup->requirement);
        if ($b !== '' && $b !== $a) {
            $set['requirement'] = mb_substr($a === '' ? $b : "{$a}\n\n(From {$dup->lead_no}) {$b}", 0, 4000);
        }
        if ($dup->first_seen_at && (!$keep->first_seen_at || $dup->first_seen_at->lt($keep->first_seen_at))) {
            $set['first_seen_at'] = $dup->first_seen_at;
        }
        $stages = LeadStages::for((int) $keep->company_id);
        if ($stages->isOpen($keep->status) && $stages->isOpen($dup->status) && $stages->rank($dup->status) > $stages->rank($keep->status)) {
            $set['status'] = $dup->status;                                    // the later stage in the pipeline
        }
        $da = $dup->next_follow_up_date;
        $ka = $keep->next_follow_up_date;
        if ($da && $keep->status !== 'on_hold' && (!$ka || $da->lt($ka))) {
            $set['next_follow_up_date'] = $da->toDateString();
        }
        if ($set) {
            $keep->forceFill($set)->save();
        }
    }

    /** The other lead's requirement lines go onto the kept lead, up to the most a lead may hold. @return int how many moved */
    private function moveLines(Lead $keep, Lead $dup): int
    {
        $room = max(0, LeadRequirementService::MAX_LINES - LeadRequirementLine::withoutGlobalScope('tenant')->where('lead_id', $keep->id)->count());
        $next = (int) LeadRequirementLine::withoutGlobalScope('tenant')->where('lead_id', $keep->id)->max('sort_order') + 1;
        $moved = 0;
        foreach (LeadRequirementLine::withoutGlobalScope('tenant')->where('lead_id', $dup->id)->orderBy('sort_order')->orderBy('id')->get() as $line) {
            if ($moved >= $room) {
                break;
            }
            $line->forceFill(['lead_id' => $keep->id, 'sort_order' => $next++])->save();
            $moved++;
        }

        return $moved;
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
