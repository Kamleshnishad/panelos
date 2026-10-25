<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LeadListService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The company's lead lists: sources and lost reasons (Settings). Reads are open to anyone who can open settings screens;
 * changes need settings.manage (the module permission map treats the /settings prefix that way).
 */
class LeadListController extends Controller
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
        return $this->successResponse([
            'sources'      => $this->lists->all('source', $this->cid($r)),
            'lost_reasons' => $this->lists->all('reason', $this->cid($r)),
        ], 'Lead lists');
    }

    public function storeSource(Request $r)
    {
        return $this->store($r, 'source');
    }

    public function updateSource(Request $r, int $id)
    {
        return $this->change($r, 'source', $id);
    }

    public function destroySource(Request $r, int $id)
    {
        return $this->remove($r, 'source', $id);
    }

    public function storeReason(Request $r)
    {
        return $this->store($r, 'reason');
    }

    public function updateReason(Request $r, int $id)
    {
        return $this->change($r, 'reason', $id);
    }

    public function destroyReason(Request $r, int $id)
    {
        return $this->remove($r, 'reason', $id);
    }

    private function store(Request $r, string $kind)
    {
        try {
            $data = $r->validate(['name' => 'required|string|max:100']);
            $this->lists->create($kind, $this->cid($r), $data['name']);

            return $this->index($r);
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }
    }

    private function change(Request $r, string $kind, int $id)
    {
        try {
            $data = $r->validate(['name' => 'sometimes|required|string|max:100', 'is_active' => 'sometimes|boolean', 'sort_order' => 'sometimes|integer|min:0|max:1000']);
            $this->lists->update($kind, $this->cid($r), $id, $data);

            return $this->index($r);
        } catch (ValidationException $e) {
            return $this->invalid($e);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'That list entry was not found.', 'NOT_FOUND', 404);
        }
    }

    private function remove(Request $r, string $kind, int $id)
    {
        try {
            $this->lists->delete($kind, $this->cid($r), $id);

            return $this->index($r);
        } catch (ValidationException $e) {
            return $this->invalid($e);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'That list entry was not found.', 'NOT_FOUND', 404);
        }
    }

    private function invalid(ValidationException $e)
    {
        return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
    }
}
