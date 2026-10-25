<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeadConnector;
use App\Services\LeadConnectorService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Settings for the IndiaMART lead connection: save the CRM key, test it. Under /settings, so anyone can see whether it is set up and
 * changes need settings.manage. The key itself is never in any answer.
 */
class LeadConnectorController extends Controller
{
    use ApiResponse;

    public function __construct(private LeadConnectorService $connectors)
    {
    }

    private function cid(Request $r): int
    {
        return (int) $r->user()->company_id;
    }

    public function showIndiaMart(Request $r)
    {
        return $this->successResponse(LeadConnector::forCompany($this->cid($r))->summary(), 'IndiaMART connection');
    }

    public function updateIndiaMart(Request $r)
    {
        try {
            $d = $r->validate(['crm_key' => 'nullable|string|max:200', 'clear_key' => 'sometimes|boolean', 'is_enabled' => 'sometimes|boolean']);
            $c = $this->connectors->saveIndiaMart($this->cid($r), $d['crm_key'] ?? null, (bool) ($d['clear_key'] ?? false));
            if (array_key_exists('is_enabled', $d) && !($d['clear_key'] ?? false)) {
                $c = $this->connectors->setEnabled($this->cid($r), (bool) $d['is_enabled']);
            }

            return $this->successResponse($c->summary(), $r->boolean('clear_key') ? 'Key removed' : 'Saved');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        }
    }

    public function pullIndiaMart(Request $r)
    {
        try {
            $d = $r->validate(['from' => 'required|date', 'to' => 'required|date']);

            return $this->successResponse($this->connectors->pullIndiaMartRange($this->cid($r), $d['from'], $d['to']), 'Pull finished');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        }
    }

    public function testIndiaMart(Request $r)
    {
        try {
            return $this->successResponse($this->connectors->testIndiaMart($this->cid($r)), 'Test finished');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        }
    }
}
