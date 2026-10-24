<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeadImportLog;
use App\Models\LeadInboundToken;
use App\Services\LeadInboundService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Settings for the inbound lead address: tokens (make, regenerate, switch off, delete) and the import log.
 * Under /settings, so reads are open and changes need settings.manage. The plain token is returned once, by store and regenerate, and never again.
 */
class LeadInboundTokenController extends Controller
{
    use ApiResponse;

    public function __construct(private LeadInboundService $inbound)
    {
    }

    private function cid(Request $r): int
    {
        return (int) $r->user()->company_id;
    }

    private function token(Request $r, int $id): LeadInboundToken
    {
        return LeadInboundToken::where('company_id', $this->cid($r))->findOrFail($id);
    }

    public function index(Request $r)
    {
        return $this->successResponse(LeadInboundToken::where('company_id', $this->cid($r))->orderBy('id')->get(), 'Inbound lead addresses');
    }

    public function store(Request $r)
    {
        try {
            $d = $r->validate(['name' => 'required|string|max:100', 'source' => 'required|string|max:60']);
            [$row, $plain] = $this->inbound->createToken($this->cid($r), $d['name'], $d['source'], $r->user()->id);

            return $this->createdResponse($row->toArray() + ['token' => $plain, 'path' => "/api/inbound/leads/{$plain}"], 'Address created. Copy it now: it is shown only once.', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        }
    }

    public function update(Request $r, int $id)
    {
        try {
            $row = $this->token($r, $id);
            $d = $r->validate(['name' => 'sometimes|required|string|max:100', 'source' => 'sometimes|required|string|max:60', 'is_active' => 'sometimes|boolean']);
            if (isset($d['source'])) {
                $resolved = app(\App\Services\LeadListService::class)->resolveSource($this->cid($r), $d['source']);
                if (!$resolved) {
                    throw ValidationException::withMessages(['source' => 'Choose one of your lead sources.']);
                }
                $d['source'] = $resolved;
            }
            $row->update($d);

            return $this->successResponse($row->fresh(), 'Updated');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'Not found', 'NOT_FOUND', 404);
        }
    }

    public function regenerate(Request $r, int $id)
    {
        try {
            [$row, $plain] = $this->inbound->regenerate($this->token($r, $id));

            return $this->successResponse($row->toArray() + ['token' => $plain, 'path' => "/api/inbound/leads/{$plain}"], 'New address made. The old one no longer works. Copy it now: it is shown only once.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'Not found', 'NOT_FOUND', 404);
        }
    }

    public function destroy(Request $r, int $id)
    {
        try {
            $this->token($r, $id)->delete();

            return $this->noContentResponse('Address deleted');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'Not found', 'NOT_FOUND', 404);
        }
    }

    /** The newest requests and, for the last 7 days, how many ended each way. */
    public function logs(Request $r)
    {
        $cid = $this->cid($r);
        $limit = min(200, max(1, (int) $r->query('limit', 50)));
        $q = LeadImportLog::where('company_id', $cid);
        if ($r->filled('token_id')) $q->where('token_id', (int) $r->query('token_id'));
        if ($r->filled('outcome'))  $q->where('outcome', $r->query('outcome'));

        return $this->successResponse([
            'summary' => LeadImportLog::where('company_id', $cid)->where('created_at', '>=', now()->subDays(7))
                ->selectRaw('outcome, count(*) as c')->groupBy('outcome')->pluck('c', 'outcome'),
            'logs' => $q->with('lead:id,lead_no')->orderByDesc('id')->limit($limit)->get()->map(fn ($l) => [
                'id' => $l->id, 'at' => $l->created_at, 'source' => $l->source, 'outcome' => $l->outcome, 'reason' => $l->reason,
                'external_id' => $l->external_id, 'token_id' => $l->token_id, 'lead_id' => $l->lead_id, 'lead_no' => $l->lead?->lead_no,
            ]),
        ], 'Import log');
    }
}
