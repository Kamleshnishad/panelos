<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A lead's next actions (LEADS_PLAN 5.2): a call, a site visit, sending the quotation ... with a day, optionally a time, and a person.
 *
 *  - Days and times are Indian time (the product is for India), whatever the server clock says.
 *  - A lead with open actions takes its follow-up date from the earliest one, so the list, the badge, the insights and the reminders of 1.7 keep working.
 *    When the last open action is done or removed the date is cleared (completing one asks "what's next?"). A lead that never had an action is never touched,
 *    and a lead on hold keeps the date it comes back on.
 *  - Every change writes a line on the lead's timeline; handing an action to someone else rings their bell.
 *  - Closing a lead (won, lost, invalid) cancels its open actions.
 */
class LeadTaskService
{
    public function __construct(private AppNotificationService $notify)
    {
    }

    public static function today(): Carbon
    {
        return Carbon::now('Asia/Kolkata')->startOfDay();
    }

    // ── the lists ────────────────────────────────────────────────────────────

    /** Open actions of one person (or everyone), in the three buckets "my day" shows. @return array{overdue:array, today:array, upcoming:array, later:int} */
    public function myDay(int $companyId, ?User $viewer, string|int|null $assignedTo = 'me'): array
    {
        $today = self::today();
        $base = fn () => $this->visibleTasks($companyId, $viewer)->where('status', LeadTask::OPEN)
            ->when($assignedTo === 'me', fn ($q) => $q->where('assigned_to_user_id', $viewer?->id ?? 0))
            ->when($assignedTo === 'none', fn ($q) => $q->whereNull('assigned_to_user_id'))
            ->when(is_numeric($assignedTo), fn ($q) => $q->where('assigned_to_user_id', (int) $assignedTo));
        $ordered = fn (Builder $q) => $q->orderBy('due_date')->orderByRaw('due_time is null')->orderBy('due_time')->orderBy('id');

        return [
            'overdue'  => $ordered($base()->whereDate('due_date', '<', $today))->limit(200)->get()->all(),
            'today'    => $ordered($base()->whereDate('due_date', $today))->limit(200)->get()->all(),
            'upcoming' => $ordered($base()->whereDate('due_date', '>', $today)->whereDate('due_date', '<=', $today->copy()->addDays(7)))->limit(200)->get()->all(),
            'later'    => $base()->whereDate('due_date', '>', $today->copy()->addDays(7))->count(),
        ];
    }

    /** Tasks the viewer may see: those of leads they may see (a person restricted to their own leads sees the actions on those). */
    public function visibleTasks(int $companyId, ?User $viewer = null): Builder
    {
        $q = LeadTask::where('company_id', $companyId)->with(['lead:id,lead_no,contact_name,company_name,phone,status,source,city', 'assignee:id,name'])
            ->whereHas('lead');                                   // an action of a deleted lead is not shown

        return $viewer && $viewer->ownLeadsOnly() ? $q->whereHas('lead', fn ($l) => $l->where('assigned_to_user_id', $viewer->id)) : $q;
    }

    /** One lead's actions: the open ones by day, then the last few done. */
    public function forLead(Lead $lead): array
    {
        $q = fn () => LeadTask::where('lead_id', $lead->id)->with('assignee:id,name');

        return [
            'open' => $q()->where('status', LeadTask::OPEN)->orderBy('due_date')->orderByRaw('due_time is null')->orderBy('due_time')->orderBy('id')->get()->all(),
            'done' => $q()->whereIn('status', [LeadTask::DONE])->orderByDesc('done_at')->limit(10)->get()->all(),
        ];
    }

    // ── changes ──────────────────────────────────────────────────────────────

    /** $meta (the system only): quotation_id and chase_step for an action planned by quotation chasing; quiet = do not ring the person it is for. */
    public function create(Lead $lead, array $d, ?User $actor = null, array $meta = []): LeadTask
    {
        return DB::transaction(function () use ($lead, $d, $actor, $meta) {
            $lead = Lead::withoutGlobalScope('tenant')->where('company_id', $lead->company_id)->lockForUpdate()->findOrFail($lead->id);
            if ($lead->isClosed()) {
                $this->refuse('lead', 'This lead is closed. Reopen it to plan an action.');
            }
            [$date, $time] = $this->when($d);
            $assignee = $this->assignee($lead, $d['assigned_to_user_id'] ?? null, $actor);

            $task = LeadTask::create([
                'company_id' => $lead->company_id, 'lead_id' => $lead->id, 'assigned_to_user_id' => $assignee, 'created_by_user_id' => $actor?->id,
                'type' => $this->type($d['type'] ?? null), 'title' => $this->title($d['title'] ?? null), 'due_date' => $date, 'due_time' => $time, 'status' => LeadTask::OPEN,
                'quotation_id' => $meta['quotation_id'] ?? null, 'chase_step' => $meta['chase_step'] ?? null,
            ]);
            $this->log($lead, $actor, 'note', "Next action planned: {$task->label()} on {$task->when()}" . $this->forWhom($task) . ($task->title ? ": {$task->title}" : ''));
            $this->syncLeadDate($lead, true);
            if (empty($meta['quiet'])) {
                $this->tell($task, $lead, $actor, 'New action for you');
            }

            return $task->fresh('assignee');
        });
    }

    /** Change an open action: what, when, who. */
    public function update(LeadTask $task, array $d, ?User $actor = null): LeadTask
    {
        return DB::transaction(function () use ($task, $d, $actor) {
            $task = LeadTask::withoutGlobalScope('tenant')->where('company_id', $task->company_id)->lockForUpdate()->findOrFail($task->id);
            if ($task->status !== LeadTask::OPEN) {
                $this->refuse('task', 'Only an action that is still open can be changed.');
            }
            $lead = Lead::withoutGlobalScope('tenant')->where('company_id', $task->company_id)->findOrFail($task->lead_id);
            $before = [$task->due_date->toDateString(), $task->due_time, $task->assigned_to_user_id];

            if (array_key_exists('type', $d))  $task->type = $this->type($d['type']);
            if (array_key_exists('title', $d)) $task->title = $this->title($d['title']);
            if (array_key_exists('due_date', $d) || array_key_exists('due_time', $d)) {
                [$task->due_date, $task->due_time] = $this->when($d + ['due_date' => $task->due_date->toDateString(), 'due_time' => $task->due_time], $task->due_date->toDateString());
            }
            if (array_key_exists('assigned_to_user_id', $d)) {
                $task->assigned_to_user_id = $this->assignee($lead, $d['assigned_to_user_id'], $actor);
            }
            $task->save();

            $moved = [$task->due_date->toDateString(), $task->due_time, $task->assigned_to_user_id] !== $before;
            if ($moved) {
                $this->log($lead, $actor, 'note', "Next action changed: {$task->label()} on {$task->when()}" . $this->forWhom($task));
            }
            $this->syncLeadDate($lead, false);
            if ($before[2] !== $task->assigned_to_user_id) {
                $this->tell($task, $lead, $actor, 'Action handed to you');
            }

            return $task->fresh('assignee');
        });
    }

    /**
     * Mark an action done, with an optional note, and plan the next one in the same step ($next has the fields of create()).
     * Without a next action the lead has none open and its follow-up date is cleared.
     * @return array{task: LeadTask, next: ?LeadTask}
     */
    public function complete(LeadTask $task, ?User $actor = null, ?string $note = null, ?array $next = null): array
    {
        return DB::transaction(function () use ($task, $actor, $note, $next) {
            $task = LeadTask::withoutGlobalScope('tenant')->where('company_id', $task->company_id)->lockForUpdate()->findOrFail($task->id);
            if ($task->status !== LeadTask::OPEN) {
                $this->refuse('task', $task->status === LeadTask::DONE ? 'This action is already done.' : 'This action was cancelled.');
            }
            $lead = Lead::withoutGlobalScope('tenant')->where('company_id', $task->company_id)->findOrFail($task->lead_id);
            $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null;

            $task->forceFill(['status' => LeadTask::DONE, 'done_at' => now(), 'done_by_user_id' => $actor?->id, 'done_note' => $note])->save();
            $type = ['call' => 'call', 'whatsapp' => 'whatsapp', 'email' => 'email', 'visit' => 'meeting'][$task->type] ?? 'note';
            $this->log($lead, $actor, $type, "Done: {$task->label()}" . ($task->title ? " ({$task->title})" : '') . ($note ? ". {$note}" : ''));

            $created = null;
            if ($next !== null && !$lead->isClosed()) {
                $created = $this->create($lead, $next, $actor);
            } else {
                $this->syncLeadDate($lead, false);
            }

            return ['task' => $task->fresh('assignee'), 'next' => $created];
        });
    }

    public function delete(LeadTask $task, ?User $actor = null): void
    {
        DB::transaction(function () use ($task, $actor) {
            $lead = Lead::withoutGlobalScope('tenant')->where('company_id', $task->company_id)->find($task->lead_id);
            $wasOpen = $task->status === LeadTask::OPEN;
            $task->delete();
            if ($lead && $wasOpen) {
                $this->log($lead, $actor, 'note', "Next action removed: {$task->label()} on {$task->when()}");
                $this->syncLeadDate($lead, false);
            }
        });
    }

    /** The quotation is no longer out with the buyer: the open chase actions made for it are cancelled and each lead's date follows what is left. @return int how many */
    public function cancelForQuotation(int $companyId, int $quotationId, string $why): int
    {
        $tasks = LeadTask::withoutGlobalScope('tenant')->where('company_id', $companyId)->where('quotation_id', $quotationId)->where('status', LeadTask::OPEN)->get();
        foreach ($tasks->groupBy('lead_id') as $leadId => $group) {
            DB::transaction(function () use ($group, $companyId, $leadId, $why) {
                LeadTask::withoutGlobalScope('tenant')->whereIn('id', $group->pluck('id'))->update(['status' => LeadTask::CANCELLED, 'updated_at' => now()]);
                if ($lead = Lead::withoutGlobalScope('tenant')->where('company_id', $companyId)->find($leadId)) {
                    $this->log($lead, null, 'note', "Quotation follow-ups cancelled: {$why}");
                    $this->syncLeadDate($lead, false);
                }
            });
        }

        return $tasks->count();
    }

    /** The lead was closed: its open actions no longer apply. Called from LeadService::transition. @return int how many */
    public function cancelOpen(Lead $lead): int
    {
        return LeadTask::withoutGlobalScope('tenant')->where('company_id', $lead->company_id)->where('lead_id', $lead->id)->where('status', LeadTask::OPEN)
            ->update(['status' => LeadTask::CANCELLED, 'updated_at' => now()]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** The lead's follow-up date follows its earliest open action. `$creating`: with no open action left, leave the date alone only when nothing was removed. */
    private function syncLeadDate(Lead $lead, bool $creating): void
    {
        if ($lead->status === 'on_hold') {
            return;                                                // the return date of a hold is its own thing
        }
        $next = LeadTask::withoutGlobalScope('tenant')->where('lead_id', $lead->id)->where('status', LeadTask::OPEN)->min('due_date');
        if ($next !== null) {
            $lead->update(['next_follow_up_date' => Carbon::parse($next)->toDateString()]);
        } elseif (!$creating) {
            $lead->update(['next_follow_up_date' => null]);
        }
    }

    /** @return array{0:string, 1:?string} the day (today or later, Indian time) and the optional time */
    private function when(array $d, ?string $keep = null): array
    {
        try {
            $date = Carbon::createFromFormat('Y-m-d', substr((string) ($d['due_date'] ?? ''), 0, 10), 'Asia/Kolkata')->startOfDay();
        } catch (\Throwable) {
            $this->refuse('due_date', 'Choose the day.');
        }
        if ($date->lt(self::today()) && $date->toDateString() !== $keep) {       // an action already overdue may keep its day while something else about it changes
            $this->refuse('due_date', 'Choose today or a later day.');
        }
        $time = $d['due_time'] ?? null;
        if ($time !== null && trim((string) $time) !== '') {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', trim((string) $time))) {
                $this->refuse('due_time', 'Give the time as hours and minutes, for example 14:30.');
            }
            $time = substr(trim((string) $time), 0, 5) . ':00';
        } else {
            $time = null;
        }

        return [$date->toDateString(), $time];
    }

    private function type(?string $t): string
    {
        if (!array_key_exists((string) $t, LeadTask::TYPES)) {
            $this->refuse('type', 'Choose what kind of action it is.');
        }

        return $t;
    }

    private function title(?string $t): ?string
    {
        $t = $t === null ? '' : trim($t);

        return $t === '' ? null : mb_substr($t, 0, 150);
    }

    /** The person it is for: one chosen (active, same company), else the lead's owner, else whoever made it. */
    private function assignee(Lead $lead, $chosen, ?User $actor): ?int
    {
        if ($chosen !== null && $chosen !== '') {
            $ok = User::withoutGlobalScope('tenant')->where('company_id', $lead->company_id)->where('is_active', true)->where('id', (int) $chosen)->exists();
            if (!$ok) {
                $this->refuse('assigned_to_user_id', 'Choose one of your active users.');
            }

            return (int) $chosen;
        }

        return $lead->assigned_to_user_id ?: $actor?->id;
    }

    private function forWhom(LeadTask $t): string
    {
        $name = $t->assigned_to_user_id ? User::withoutGlobalScope('tenant')->whereKey($t->assigned_to_user_id)->value('name') : null;

        return $name ? " ({$name})" : '';
    }

    private function log(Lead $lead, ?User $actor, string $type, string $text): void
    {
        LeadActivity::create(['company_id' => $lead->company_id, 'lead_id' => $lead->id, 'user_id' => $actor?->id, 'type' => $type, 'description' => $text, 'activity_date' => now()]);
    }

    /** Ring the person it is for, unless they made the change themselves. */
    private function tell(LeadTask $t, Lead $lead, ?User $actor, string $headline): void
    {
        if (!$t->assigned_to_user_id || $t->assigned_to_user_id === $actor?->id) {
            return;
        }
        $this->notify->notify($lead->company_id, [$t->assigned_to_user_id], 'lead_task_assigned', "{$headline}: {$t->label()} {$lead->lead_no} {$lead->contact_name}",
            "Due {$t->when()}" . ($t->title ? ". {$t->title}" : ''), 'leads', $lead->id, 'info');
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
