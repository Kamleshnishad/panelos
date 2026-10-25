<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LeadMergeService;
use App\Services\LeadService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Merging two leads for the same buyer (LEADS_PLAN 5.8). The lead in the address is KEPT; the one named in the body is folded into it. Needs leads.manage; both leads
 * must be ones this person may see (someone else's lead, under leads.own_only, is a plain 404). See LeadMergeService.
 */
class LeadMergeController extends Controller
{
    use ApiResponse;

    public function __construct(private LeadMergeService $merge, private LeadService $leads)
    {
    }

    private function lead(Request $r, int $id)
    {
        return $this->leads->visible((int) $r->user()->company_id, $r->user())->findOrFail($id);
    }

    public function candidates(Request $r, int $id)
    {
        try {
            return $this->successResponse(['candidates' => $this->merge->candidates($this->lead($r, $id), $r->user(), $r->query('q'))], 'Leads that could be merged into this one');
        } catch (ModelNotFoundException) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);
        }
    }

    public function merge(Request $r, int $id)
    {
        try {
            $d = $r->validate(['from_lead_id' => 'required|integer']);
            $res = $this->merge->merge($this->lead($r, $id), $this->lead($r, (int) $d['from_lead_id']), $r->user());

            return $this->successResponse($res, "{$res['merged']} was merged into {$res['kept']}");
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (ModelNotFoundException) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);
        }
    }
}
