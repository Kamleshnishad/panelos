<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeadTask;
use App\Services\LeadBulkService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Select-many actions on the Leads list (LEADS_PLAN 5.8): POST /leads/bulk. Needs leads.manage like any change; only leads the person may see are touched.
 * The answer says how many were done and lists the ones that were skipped, with the reason for each. See LeadBulkService.
 */
class LeadBulkController extends Controller
{
    use ApiResponse;

    public function __construct(private LeadBulkService $bulk)
    {
    }

    public function run(Request $r)
    {
        try {
            $d = $r->validate([
                'ids' => 'required|array|min:1|max:' . LeadBulkService::MAX, 'ids.*' => 'integer',
                'action' => 'required|string|max:12',
                'user_id' => 'nullable', 'status' => 'nullable|string|max:40', 'reason' => 'nullable|string|max:500', 'hold_until' => 'nullable|date',
                'lost_reason_id' => 'nullable|integer', 'lost_note' => 'nullable|string|max:500',
                'type' => 'nullable|in:' . implode(',', array_keys(LeadTask::TYPES)), 'due_date' => 'nullable|date_format:Y-m-d', 'due_time' => 'nullable|string|max:8',
                'title' => 'nullable|string|max:150', 'assigned_to_user_id' => 'nullable|integer',
            ]);
            $res = $this->bulk->run($r->user(), $d['ids'], $d['action'], $r->except(['ids', 'action']));

            return $this->successResponse($res, "{$res['done']} of {$res['requested']} done");
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        }
    }
}
