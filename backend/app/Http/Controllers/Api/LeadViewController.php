<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadView;
use App\Services\LeadService;
use App\Services\LeadStages;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A person's saved views of the lead list (LEADS_PLAN 5.1). Private: you only ever see and change your own. Saving under a name you already have
 * replaces that view. Only the filters the list understands are kept, so a view can never carry anything else.
 */
class LeadViewController extends Controller
{
    use ApiResponse;

    public const MAX_PER_PERSON = 20;

    private function mine(Request $r)
    {
        return LeadView::where('company_id', $r->user()->company_id)->where('user_id', $r->user()->id);
    }

    public function index(Request $r)
    {
        return $this->successResponse($this->mine($r)->orderBy('name')->get(['id', 'name', 'filters']), 'Saved lead views');
    }

    public function store(Request $r)
    {
        try {
            $d = $r->validate(['name' => 'required|string|max:60', 'filters' => 'present|array']);
            $name = trim($d['name']);
            if ($name === '') {
                throw ValidationException::withMessages(['name' => 'Give the view a name.']);
            }
            $filters = $this->clean($d['filters'], (int) $r->user()->company_id);

            $view = $this->mine($r)->where('name', $name)->first();
            if (!$view && $this->mine($r)->count() >= self::MAX_PER_PERSON) {
                throw ValidationException::withMessages(['name' => 'You can keep ' . self::MAX_PER_PERSON . ' views. Delete one first.']);
            }
            $view ? $view->update(['filters' => $filters])
                  : $view = LeadView::create(['company_id' => $r->user()->company_id, 'user_id' => $r->user()->id, 'name' => $name, 'filters' => $filters]);

            return $this->createdResponse($view->only(['id', 'name', 'filters']), 'View saved', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        }
    }

    public function destroy(Request $r, int $id)
    {
        $view = $this->mine($r)->find($id);
        if (!$view) {
            return $this->errorResponse([], 'Not found', 'NOT_FOUND', 404);
        }
        $view->delete();

        return $this->noContentResponse('View deleted');
    }

    /** Keep only what the list understands, in a shape it accepts. */
    private function clean(array $f, int $company): array
    {
        $s = fn ($k, $max) => isset($f[$k]) && is_scalar($f[$k]) && trim((string) $f[$k]) !== '' ? mb_substr(trim((string) $f[$k]), 0, $max) : null;
        $out = [
            'status'      => LeadStages::for($company)->has($s('status', 40)) ? $s('status', 40) : null,
            'source'      => $s('source', 60),
            'assigned_to' => preg_match('/^(me|none|\d{1,12})$/', (string) $s('assigned_to', 12)) ? $s('assigned_to', 12) : null,
            'follow_up'   => in_array($s('follow_up', 10), LeadService::FOLLOW_UPS, true) ? $s('follow_up', 10) : null,
            'search'      => $s('search', 100),
            'quiet'       => $s('quiet', 1) === '1' ? '1' : null,
            'unanswered'  => $s('unanswered', 1) === '1' ? '1' : null,
            'sort'        => in_array($s('sort', 12), LeadService::SORTS, true) ? $s('sort', 12) : null,
            'dir'         => in_array($s('dir', 4), ['asc', 'desc'], true) ? $s('dir', 4) : null,
        ];

        return array_filter($out, fn ($v) => $v !== null);
    }
}
