<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeadTask;
use App\Services\LeadService;
use App\Services\LeadTaskService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Next actions on leads (LEADS_PLAN 5.2): the "my day" list, one lead's actions, and adding, changing, completing and removing them.
 * Under the leads module: reading needs leads.view, changing needs leads.manage. A person restricted to their own leads (leads.own_only) only meets
 * the actions of those leads; anything else is simply not found.
 */
class LeadTaskController extends Controller
{
    use ApiResponse;

    public function __construct(private LeadTaskService $tasks, private LeadService $leads)
    {
    }

    private function cid(Request $r): int
    {
        return (int) $r->user()->company_id;
    }

    private function lead(Request $r, int $id)
    {
        return $this->leads->visible($this->cid($r), $r->user())->findOrFail($id);
    }

    private function task(Request $r, int $id): LeadTask
    {
        $t = $this->tasks->visibleTasks($this->cid($r), $r->user())->findOrFail($id);

        return $t;
    }

    private function row(LeadTask $t): array
    {
        return [
            'id' => $t->id, 'lead_id' => $t->lead_id, 'type' => $t->type, 'type_label' => $t->label(), 'title' => $t->title,
            'due_date' => $t->due_date->toDateString(), 'due_time' => $t->due_time ? substr($t->due_time, 0, 5) : null, 'status' => $t->status,
            'assigned_to_user_id' => $t->assigned_to_user_id, 'assignee' => $t->assignee?->name, 'auto' => $t->chase_step !== null, 'done_at' => $t->done_at, 'done_note' => $t->done_note,
            'lead' => $t->relationLoaded('lead') && $t->lead ? $t->lead->only(['id', 'lead_no', 'contact_name', 'company_name', 'phone', 'status', 'source', 'city']) : null,
        ];
    }

    private function rules(bool $partial = false): array
    {
        $s = $partial ? 'sometimes|' : '';

        return [
            'type' => $s . 'required|in:' . implode(',', array_keys(LeadTask::TYPES)),
            'title' => 'nullable|string|max:150',
            'due_date' => $s . 'required|date_format:Y-m-d',
            'due_time' => 'nullable|string|max:8',
            'assigned_to_user_id' => 'nullable|integer',
        ];
    }

    private function invalid(ValidationException $e)
    {
        return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
    }

    private function missing()
    {
        return $this->errorResponse([], 'Not found', 'NOT_FOUND', 404);
    }

    /** "My day": open actions in three buckets, for me, a person, nobody, or everyone (assigned_to=all). */
    public function index(Request $r)
    {
        $to = $r->query('assigned_to', 'me');
        $to = $to === 'all' ? null : $to;
        $day = $this->tasks->myDay($this->cid($r), $r->user(), $to ?? 'all');

        return $this->successResponse([
            'overdue' => array_map([$this, 'row'], $day['overdue']), 'today' => array_map([$this, 'row'], $day['today']),
            'upcoming' => array_map([$this, 'row'], $day['upcoming']), 'later' => $day['later'], 'types' => LeadTask::TYPES,
        ], 'Next actions');
    }

    public function forLead(Request $r, int $id)
    {
        try {
            $l = $this->tasks->forLead($this->lead($r, $id));

            return $this->successResponse(['open' => array_map([$this, 'row'], $l['open']), 'done' => array_map([$this, 'row'], $l['done']), 'types' => LeadTask::TYPES], 'Next actions of the lead');
        } catch (ModelNotFoundException) {
            return $this->missing();
        }
    }

    public function store(Request $r, int $id)
    {
        try {
            $d = $r->validate($this->rules());
            $task = $this->tasks->create($this->lead($r, $id), $d, $r->user());

            return $this->createdResponse($this->row($task), 'Next action planned', 201);
        } catch (ValidationException $e) {
            return $this->invalid($e);
        } catch (ModelNotFoundException) {
            return $this->missing();
        }
    }

    public function update(Request $r, int $id)
    {
        try {
            $d = $r->validate($this->rules(true));

            return $this->successResponse($this->row($this->tasks->update($this->task($r, $id), $d, $r->user())), 'Next action changed');
        } catch (ValidationException $e) {
            return $this->invalid($e);
        } catch (ModelNotFoundException) {
            return $this->missing();
        }
    }

    /** Done, with an optional note and, in the same call, what happens next (`next`: type, due_date, due_time, title, assigned_to_user_id). */
    public function complete(Request $r, int $id)
    {
        try {
            $d = $r->validate([
                'note' => 'nullable|string|max:500', 'next' => 'nullable|array',
                'next.type' => 'required_with:next|in:' . implode(',', array_keys(LeadTask::TYPES)), 'next.due_date' => 'required_with:next|date_format:Y-m-d',
                'next.due_time' => 'nullable|string|max:8', 'next.title' => 'nullable|string|max:150', 'next.assigned_to_user_id' => 'nullable|integer',
            ]);
            $res = $this->tasks->complete($this->task($r, $id), $r->user(), $d['note'] ?? null, $d['next'] ?? null);

            return $this->successResponse(['task' => $this->row($res['task']), 'next' => $res['next'] ? $this->row($res['next']) : null], 'Action done');
        } catch (ValidationException $e) {
            return $this->invalid($e);
        } catch (ModelNotFoundException) {
            return $this->missing();
        }
    }

    public function destroy(Request $r, int $id)
    {
        try {
            $this->tasks->delete($this->task($r, $id), $r->user());

            return $this->noContentResponse('Next action removed');
        } catch (ModelNotFoundException) {
            return $this->missing();
        }
    }
}
