<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Select-many actions on the Leads list (LEADS_PLAN 5.8, audit LP-09). Each lead goes through the same service call a single change would use, one at a time, so every
 * rule holds exactly as it does for one lead and each change writes its own timeline line. A lead that cannot take the change is SKIPPED with the reason; the rest
 * are done; nothing is all-or-nothing and nothing is silently dropped.
 *
 *  - assign: to a person or to nobody. Closed leads are not reassigned. The new owner gets ONE line ("12 leads assigned to you by Asha"), not twelve.
 *  - status: contacted, qualified, on hold (with a return date), lost (with a reason) or invalid. The ordinary status rules apply, so a closed lead is skipped (reopening
 *    needs its own reason) and a move that is not allowed is skipped with the rule's own words.
 *  - plan: the same next action (kind, day, optional time, note, person) for each lead; closed leads are skipped. Each person it is for gets ONE line.
 * Only leads the person may see are touched (another company's, or someone else's under leads.own_only, is reported as not found). At most 200 at a time.
 * Nothing is deleted in bulk.
 */
class LeadBulkService
{
    public const MAX = 200;
    public const ACTIONS = ['assign', 'status', 'plan'];
    public const STATUSES = ['contacted', 'qualified', 'on_hold', 'lost', 'invalid'];

    public function __construct(private LeadService $leads, private LeadTaskService $tasks, private AppNotificationService $notify, private LeadListService $lists)
    {
    }

    /**
     * @param list<int> $ids
     * @return array{action:string, requested:int, done:int, skipped:list<array{id:int,lead_no:?string,reason:string}>}
     * @throws ValidationException when the action itself is not usable (no such action, nobody chosen, no reason ...)
     */
    public function run(User $actor, array $ids, string $action, array $p = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            $this->refuse('ids', 'Choose at least one lead.');
        }
        if (count($ids) > self::MAX) {
            $this->refuse('ids', 'Choose up to ' . self::MAX . ' leads at a time.');
        }
        if (!in_array($action, self::ACTIONS, true)) {
            $this->refuse('action', 'That is not something that can be done to many leads at once.');
        }
        $companyId = (int) $actor->company_id;
        $found = $this->leads->visible($companyId, $actor)->whereIn('leads.id', $ids)->get()->keyBy('id');
        $prepared = match ($action) {
            'assign' => $this->prepareAssign($companyId, $p),
            'status' => $this->prepareStatus($companyId, $p),
            'plan'   => $this->preparePlan($companyId, $p),
        };

        $done = 0;
        $skipped = [];
        $perPerson = [];
        $held = LeadAssignmentService::holdAlerts(function () use ($ids, $found, $action, $prepared, $actor, &$done, &$skipped, &$perPerson) {
            foreach ($ids as $id) {
                $lead = $found->get($id);
                if (!$lead) {
                    $skipped[] = ['id' => $id, 'lead_no' => null, 'reason' => 'Not found.'];
                    continue;
                }
                try {
                    $who = match ($action) {
                        'assign' => $this->assign($lead, $prepared),
                        'status' => $this->status($lead, $prepared, $actor),
                        'plan'   => $this->plan($lead, $prepared, $actor),
                    };
                    $done++;
                    if ($who) {
                        $perPerson[$who] = ($perPerson[$who] ?? 0) + 1;
                    }
                } catch (ValidationException $e) {
                    $skipped[] = ['id' => $id, 'lead_no' => $lead->lead_no, 'reason' => (string) collect($e->errors())->flatten()->first()];
                }
            }
        });

        $this->tell($actor, $action, $held, $perPerson);

        return ['action' => $action, 'requested' => count($ids), 'done' => $done, 'skipped' => array_slice($skipped, 0, 50), 'skipped_count' => count($skipped)];
    }

    // ── assign ───────────────────────────────────────────────────────────────

    private function prepareAssign(int $companyId, array $p): array
    {
        $to = $p['user_id'] ?? null;
        if ($to === null || $to === '' || $to === 'none') {
            return ['user' => null];
        }
        $u = User::withoutGlobalScope('tenant')->where('company_id', $companyId)->where('is_active', true)->find((int) $to);
        if (!$u) {
            $this->refuse('user_id', 'Choose one of your active users.');
        }

        return ['user' => $u->id];
    }

    private function assign(Lead $lead, array $o): ?int
    {
        if ($lead->isClosed()) {
            $this->refuse('lead', "{$lead->lead_no} is {$lead->status}; closed leads are not reassigned.");
        }
        if ((int) $lead->assigned_to_user_id === (int) $o['user']) {
            $this->refuse('lead', 'Already with that person.');
        }
        $this->leads->update($lead, ['assigned_to_user_id' => $o['user']]);

        return null;                                                          // the summary comes from the held alerts
    }

    // ── status ───────────────────────────────────────────────────────────────

    private function prepareStatus(int $companyId, array $p): array
    {
        $to = (string) ($p['status'] ?? '');
        if (!in_array($to, self::STATUSES, true)) {
            $this->refuse('status', 'Choose contacted, qualified, on hold, lost or invalid.');
        }
        $o = ['reason' => $p['reason'] ?? null];
        if ($to === 'on_hold') {
            if (trim((string) ($p['hold_until'] ?? '')) === '') {
                $this->refuse('hold_until', 'Choose the date the leads come back.');
            }
            $o['hold_until'] = $p['hold_until'];
        }
        if ($to === 'lost') {
            $reason = null;
            if (!empty($p['lost_reason_id'])) {
                $reason = collect($this->lists->all('reason', $companyId))->firstWhere('id', (int) $p['lost_reason_id']);
            }
            if (!$reason || !$reason['is_active']) {
                $this->refuse('lost_reason_id', 'Choose why they were lost.');
            }
            $o += ['lost_reason' => $reason['name'], 'lost_reason_id' => $reason['id'], 'lost_note' => $p['lost_note'] ?? null];
        }

        return ['to' => $to, 'options' => $o];
    }

    private function status(Lead $lead, array $o, User $actor): ?int
    {
        if ($lead->status === $o['to']) {
            $this->refuse('lead', 'Already ' . str_replace('_', ' ', $o['to']) . '.');
        }
        if ($lead->isClosed()) {
            $this->refuse('lead', "{$lead->lead_no} is {$lead->status}. Reopen it on its own first (that needs a reason); it is not changed here.");
        }
        $this->leads->transition($lead, $o['to'], $o['options'], $actor->id);

        return null;
    }

    // ── plan ─────────────────────────────────────────────────────────────────

    private function preparePlan(int $companyId, array $p): array
    {
        $d = array_intersect_key($p, array_flip(['type', 'due_date', 'due_time', 'title', 'assigned_to_user_id']));
        if (empty($d['type']) || empty($d['due_date'])) {
            $this->refuse('due_date', 'Choose what to do and on which day.');
        }

        return $d;
    }

    private function plan(Lead $lead, array $d, User $actor): ?int
    {
        $task = $this->tasks->create($lead, $d, $actor, ['quiet' => true]);         // the one summary below replaces a bell per lead

        return $task->assigned_to_user_id && $task->assigned_to_user_id !== $actor->id ? (int) $task->assigned_to_user_id : null;
    }

    // ── the one line each person gets ────────────────────────────────────────

    /** @param array<int,int> $held assignments that would each have rung a bell; @param array<int,int> $perPerson actions planned for someone else */
    private function tell(User $actor, string $action, array $held, array $perPerson): void
    {
        foreach ($held as $userId => $n) {
            if ((int) $userId === $actor->id) {
                continue;
            }
            $this->notify->notify((int) $actor->company_id, [(int) $userId], 'lead_assigned', "{$n} lead" . ($n === 1 ? '' : 's') . " assigned to you by {$actor->name}", 'Open Leads and choose My leads.', 'leads', null, 'info');
        }
        foreach ($perPerson as $userId => $n) {
            $this->notify->notify((int) $actor->company_id, [(int) $userId], 'lead_task_assigned', "{$n} action" . ($n === 1 ? '' : 's') . " planned for you by {$actor->name}", 'Open Leads and choose My day.', 'leads', null, 'info');
        }
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
