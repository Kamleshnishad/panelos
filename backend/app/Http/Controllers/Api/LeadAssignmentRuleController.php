<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeadAssignmentRule;
use App\Models\User;
use App\Services\LeadListService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The company's lead assignment rules (Settings; reads open, changes need settings.manage). None by default: leads stay unassigned.
 * The first active rule, lowest priority number first, whose conditions match a new lead gives it to someone in its list, taking turns.
 */
class LeadAssignmentRuleController extends Controller
{
    use ApiResponse;

    public function __construct(private LeadListService $lists)
    {
    }

    private function cid(Request $r): int
    {
        return (int) $r->user()->company_id;
    }

    public function index(Request $r)
    {
        return $this->successResponse(LeadAssignmentRule::where('company_id', $this->cid($r))->orderBy('priority')->orderBy('id')->get(), 'Assignment rules');
    }

    public function store(Request $r)
    {
        return $this->save($r, new LeadAssignmentRule(['company_id' => $this->cid($r)]), true);
    }

    public function update(Request $r, int $id)
    {
        $rule = LeadAssignmentRule::where('company_id', $this->cid($r))->find($id);

        return $rule ? $this->save($r, $rule, false) : $this->errorResponse([], 'Not found', 'NOT_FOUND', 404);
    }

    public function destroy(Request $r, int $id)
    {
        $rule = LeadAssignmentRule::where('company_id', $this->cid($r))->find($id);
        if (!$rule) {
            return $this->errorResponse([], 'Not found', 'NOT_FOUND', 404);
        }
        $rule->delete();

        return $this->noContentResponse('Rule deleted');
    }

    private function save(Request $r, LeadAssignmentRule $rule, bool $creating)
    {
        try {
            $cid = $this->cid($r);
            $d = $r->validate([
                'name'        => ($creating ? 'required' : 'sometimes|required') . '|string|max:100',
                'priority'    => 'sometimes|integer|min:0|max:65000',
                'is_active'   => 'sometimes|boolean',
                'source'      => 'sometimes|nullable|string|max:60',
                'city'        => 'sometimes|nullable|string|max:120',
                'application' => 'sometimes|nullable|string|max:40',
                'user_ids'    => ($creating ? 'required' : 'sometimes|required') . '|array|min:1|max:30',
                'user_ids.*'  => 'integer|distinct',
            ]);

            if (!empty($d['source'])) {
                $d['source'] = $this->lists->resolveSource($cid, $d['source'])
                    ?? throw ValidationException::withMessages(['source' => 'Choose one of your lead sources.']);
            }
            foreach (['source', 'city', 'application'] as $k) {
                if (array_key_exists($k, $d) && trim((string) $d[$k]) === '') $d[$k] = null;
            }
            if (isset($d['user_ids'])) {
                $ok = User::where('company_id', $cid)->where('is_active', true)->whereIn('id', $d['user_ids'])->pluck('id')->all();
                if (count($ok) !== count($d['user_ids'])) {
                    throw ValidationException::withMessages(['user_ids' => 'Everyone in a rule must be an active user of your company.']);
                }
                $d['user_ids'] = array_map('intval', $d['user_ids']);
                $d['last_user_id'] = null;                     // a changed list starts its rotation again
            }
            if ($creating && !isset($d['priority'])) {
                $d['priority'] = (int) LeadAssignmentRule::where('company_id', $cid)->max('priority') + 1;
            }
            $rule->fill($d)->save();

            return $creating ? $this->createdResponse($rule->fresh(), 'Rule created', 201) : $this->successResponse($rule->fresh(), 'Rule updated');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        }
    }
}
