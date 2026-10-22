<?php

namespace App\Models;

use Illuminate\Support\Facades\Storage;

/**
 * A photo/signature attached to some record (a batch stage log, a dispatch).
 * One polymorphic table for all attachment types instead of one-off columns
 * per model — see mobile_plan/09_BACKEND_CHANGES_REQUIRED.md §1.
 */
class Attachment extends BaseModel
{
    protected $fillable = [
        'company_id',
        'attachable_type',
        'attachable_id',
        'type',
        'file_path',
        'uploaded_by',
    ];

    protected $appends = ['url'];

    public function attachable()
    {
        return $this->morphTo();
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }
}
