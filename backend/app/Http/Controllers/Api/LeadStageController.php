<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeadStage;
use App\Services\LeadStageService;
use App\Services\LeadStages;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * The company's lead stages (LEADS_PLAN 5.8), under Settings: reading is open, changing needs settings.manage. See LeadStageService for the rules;
 * a refused change is a 422 with the reason (the app-wide ValidationException renderer).
 */
class LeadStageController extends Controller
{
    use ApiResponse;

    public function __construct(private LeadStageService $stages)
    {
    }

    private function cid(Request $r): int
    {
        return (int) $r->user()->company_id;
    }

    private function body(Request $r): array
    {
        return ['stages' => $this->stages->list($this->cid($r)), 'fixed' => LeadStages::FIXED, 'max' => LeadStages::MAX];
    }

    private function find(Request $r, int $id): ?LeadStage
    {
        return LeadStage::where('company_id', $this->cid($r))->find($id);
    }

    public function index(Request $r)
    {
        return $this->successResponse($this->body($r), 'Lead stages');
    }

    public function store(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:60', 'probability' => 'required']);
        $this->stages->create($this->cid($r), $d['name'], $d['probability']);

        return $this->createdResponse($this->body($r), 'Stage added');
    }

    public function update(Request $r, int $id)
    {
        if (!$stage = $this->find($r, $id)) {
            return $this->errorResponse([], 'Not found', 'NOT_FOUND', 404);
        }
        $d = $r->validate(['name' => 'sometimes|required|string|max:60', 'probability' => 'sometimes|required']);
        $this->stages->update($stage, $d);

        return $this->successResponse($this->body($r), 'Stage saved');
    }

    public function reorder(Request $r)
    {
        $d = $r->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        $this->stages->reorder($this->cid($r), $d['ids']);

        return $this->successResponse($this->body($r), 'Order saved');
    }

    public function destroy(Request $r, int $id)
    {
        if (!$stage = $this->find($r, $id)) {
            return $this->errorResponse([], 'Not found', 'NOT_FOUND', 404);
        }
        $this->stages->delete($stage);

        return $this->successResponse($this->body($r), 'Stage removed');
    }
}
