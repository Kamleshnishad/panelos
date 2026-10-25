<?php

namespace App\Models;

/** One spreadsheet import of leads (LEADS_PLAN 4.2): checked, imported, maybe undone. */
class LeadImport extends BaseModel
{
    public const PREVIEWED = 'previewed';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const UNDONE = 'undone';

    protected $fillable = [
        'company_id', 'user_id', 'file_name', 'source', 'status', 'total_rows', 'counts', 'rows', 'created_lead_ids', 'undo_result', 'imported_at', 'undone_at',
    ];

    protected $hidden = ['rows'];

    protected $casts = [
        'counts'           => 'array',
        'rows'             => 'array',
        'created_lead_ids' => 'array',
        'undo_result'      => 'array',
        'imported_at'      => 'datetime',
        'undone_at'        => 'datetime',
    ];

    /** What the screens may see (never the stored rows, which hold the buyers' details). */
    public function summary(): array
    {
        return [
            'id' => $this->id, 'file_name' => $this->file_name, 'source' => $this->source, 'status' => $this->status, 'total_rows' => $this->total_rows,
            'counts' => $this->counts, 'created' => count($this->created_lead_ids ?? []), 'undo_result' => $this->undo_result,
            'imported_at' => $this->imported_at, 'undone_at' => $this->undone_at,
        ];
    }
}
