<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WasteReasonCode;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** WR-0: tenant-editable reasons for rejections and waste. */
class WasteReasonCodeController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $companyId = $request->user()->company_id;
        WasteReasonCode::ensureDefaults($companyId);

        $query = WasteReasonCode::where('company_id', $companyId)->orderBy('sort_order')->orderBy('id');
        if ($request->filled('category')) $query->where('category', $request->input('category'));
        if ($request->boolean('active_only')) $query->where('is_active', true);

        return $this->successResponse($query->get(), 'Reason codes retrieved');
    }

    public function store(Request $request)
    {
        try {
            $companyId = $request->user()->company_id;
            $data = $request->validate($this->rules($companyId));
            $data['code'] = strtoupper($data['code']);
            $data['company_id'] = $companyId;
            $data['sort_order'] = $data['sort_order']
                ?? (int) WasteReasonCode::where('company_id', $companyId)->max('sort_order') + 1;
            return $this->successResponse(WasteReasonCode::create($data), 'Reason code created', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        }
    }

    public function update(Request $request, int $id)
    {
        try {
            $companyId = $request->user()->company_id;
            $code = WasteReasonCode::where('company_id', $companyId)->findOrFail($id);
            $data = $request->validate($this->rules($companyId, $code->id, partial: true));
            if (isset($data['code'])) $data['code'] = strtoupper($data['code']);
            $code->update($data);
            return $this->successResponse($code->fresh(), 'Reason code updated');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(['id' => ['Reason code not found']], 'Not found', 'NOT_FOUND', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        }
    }

    private function rules(int $companyId, ?int $ignoreId = null, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        return [
            'code'            => [$req, 'string', 'max:40', 'regex:/^[A-Za-z0-9_]+$/',
                                  Rule::unique('waste_reason_codes', 'code')->where('company_id', $companyId)->ignore($ignoreId)],
            'label'           => [$req, 'string', 'max:120'],
            'category'        => [$req, Rule::in(WasteReasonCode::CATEGORIES)],
            'root_cause_hint' => ['nullable', 'string', 'max:255'],
            'is_active'       => ['sometimes', 'boolean'],
            'sort_order'      => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
