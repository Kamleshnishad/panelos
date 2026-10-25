<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PanelType;
use App\Support\TallyLedgers;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * The ledger names and fallback HSN code of the Tally export (FR-010), under Settings: reading is open, changing needs settings.manage.
 * Leave a field empty to go back to its default. See TallyLedgers.
 */
class TallyLedgerController extends Controller
{
    use ApiResponse;

    private function body(int $companyId): array
    {
        return [
            'ledgers'  => TallyLedgers::for($companyId),
            'defaults' => TallyLedgers::DEFAULTS,
            // Panel types an export would have to give the fallback code to: the accountant should know.
            'panel_types_without_hsn' => PanelType::where('company_id', $companyId)->where('is_active', true)->where(fn ($q) => $q->whereNull('hsn_code')->orWhere('hsn_code', ''))->pluck('name')->all(),
        ];
    }

    public function show(Request $r)
    {
        return $this->successResponse($this->body((int) $r->user()->company_id), 'Tally settings');
    }

    public function update(Request $r)
    {
        $d = $r->validate([
            'sales' => ['nullable', 'string', 'max:80', 'regex:/^[^\x00-\x1F<>]*$/'],
            'cgst'  => ['nullable', 'string', 'max:80', 'regex:/^[^\x00-\x1F<>]*$/'],
            'sgst'  => ['nullable', 'string', 'max:80', 'regex:/^[^\x00-\x1F<>]*$/'],
            'igst'  => ['nullable', 'string', 'max:80', 'regex:/^[^\x00-\x1F<>]*$/'],
            'hsn'   => ['nullable', 'regex:/^\d{4,8}$/'],
        ], [
            '*.regex' => 'Ledger names cannot contain < or > or control characters.',
            'hsn.regex' => 'An HSN code is 4 to 8 digits.',
            '*.max' => 'Keep each name to 80 characters.',
        ]);
        TallyLedgers::save((int) $r->user()->company_id, array_map(fn ($v) => $v === null ? '' : trim($v), $d));

        return $this->successResponse($this->body((int) $r->user()->company_id), 'Tally settings saved');
    }
}
