<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Services\LeadImportService;
use App\Services\LeadService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Leads in and out as a spreadsheet (LEADS_PLAN 4.2): the template, the check of a file, the import, the undo of the last import, and the export of
 * the list as filtered on screen. Importing needs leads.manage like any change to leads; the export needs reports.view like the other bulk exports
 * (a whole contact list leaving in one click is a different thing from seeing leads one at a time).
 */
class LeadSheetController extends Controller
{
    use ApiResponse;

    public const PREVIEW_ROWS = 300;

    public function __construct(private LeadImportService $imports, private LeadService $leads)
    {
    }

    private function cid(Request $r): int
    {
        return (int) $r->user()->company_id;
    }

    public function template()
    {
        return response()->streamDownload(fn () => print(LeadImportService::templateCsv()), 'leads_import_template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The leads as the list shows them (same filters), newest first, streamed so a large list never sits in memory. */
    public function export(Request $r)
    {
        $query = $this->leads->list($this->cid($r), $r->only(['status', 'source', 'assigned_to', 'follow_up', 'search']), $r->user())->with('assignedUser:id,name');

        return response()->streamDownload(function () use ($query) {
            echo LeadImportService::csv([[
                'Lead No', 'Date', 'Name', 'Company', 'Phone', 'Email', 'City', 'Source', 'Campaign', 'Status', 'Assigned To', 'Next Follow-up', 'Application',
                'Area (sqm)', 'Value (Rs)', 'Requirement', 'Lost Reason', 'Page', 'UTM Source', 'UTM Medium', 'UTM Campaign',
            ]]);
            $query->reorder()->chunkByIdDesc(500, function ($leads) {
                foreach ($leads as $l) {
                    echo LeadImportService::line([
                        $l->lead_no, $l->created_at?->timezone('Asia/Kolkata')->format('Y-m-d H:i'), $l->contact_name, $l->company_name, $l->phone, $l->email, $l->city,
                        $l->source, $l->campaign, $l->status, $l->assignedUser?->name, $l->next_follow_up_date?->format('Y-m-d'), $l->application,
                        $l->est_qty_sqm, $l->est_value, $l->requirement, $l->lost_reason, $l->landing_page, $l->utm_source, $l->utm_medium, $l->utm_campaign,
                    ]);
                }
            });
        }, 'leads_' . now()->format('Ymd') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The most recent import that ran (what Undo applies to), or null. */
    public function last(Request $r)
    {
        return $this->successResponse($this->imports->last($this->cid($r))?->summary(), 'Last import');
    }

    /** Check a file: nothing is created. */
    public function preview(Request $r)
    {
        try {
            $r->validate(['file' => 'required|file|max:2048', 'source' => 'nullable|string|max:60']);
            $file = $r->file('file');
            $imp = $this->imports->preview($this->cid($r), $r->user()->id, $file->getClientOriginalName(), (string) file_get_contents($file->getRealPath()), $r->input('source'));
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        }

        return $this->successResponse($imp->summary() + [
            'ignored_columns' => $imp->counts['ignored_columns'] ?? [],
            'rows' => collect($imp->rows)->take(self::PREVIEW_ROWS)->map(fn ($x) => [
                'n' => $x['n'], 'name' => $x['data']['contact_name'] ?? ($x['data']['company_name'] ?? ''), 'phone' => $x['data']['phone'] ?? '', 'email' => $x['data']['email'] ?? '',
                'city' => $x['data']['city'] ?? '', 'source' => $x['source'], 'outcome' => $x['outcome'], 'reason' => $x['reason'], 'notes' => $x['notes'],
            ])->values(),
            'rows_shown' => min(self::PREVIEW_ROWS, count($imp->rows)),
        ], 'File checked. Nothing has been imported yet.');
    }

    private function mine(Request $r, int $id): LeadImport
    {
        return LeadImport::where('company_id', $this->cid($r))->findOrFail($id);
    }

    public function commit(Request $r, int $id)
    {
        try {
            @set_time_limit(180);
            $imp = $this->imports->commit($this->mine($r, $id));

            return $this->successResponse($imp->summary(), 'Import finished');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'Not found', 'NOT_FOUND', 404);
        }
    }

    public function undo(Request $r, int $id)
    {
        try {
            return $this->successResponse($this->imports->undo($this->mine($r, $id))->summary(), 'Import undone');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'Not found', 'NOT_FOUND', 404);
        }
    }
}
