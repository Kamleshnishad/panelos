<?php

namespace App\Models;

/** A file kept on a lead (LEADS_PLAN 5.7): a drawing, photo, BOQ sheet or document. The file is on the private disk; `path` is never sent to a screen. */
class LeadAttachment extends BaseModel
{
    public const KINDS = ['drawing' => 'Drawing', 'photo' => 'Photo', 'boq' => 'BOQ or sheet', 'document' => 'Document'];

    protected $fillable = ['company_id', 'lead_id', 'uploaded_by_user_id', 'kind', 'original_name', 'path', 'extension', 'size_bytes'];

    protected $hidden = ['path'];

    protected $casts = ['size_bytes' => 'integer'];

    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by_user_id'); }
    public function lead() { return $this->belongsTo(Lead::class); }
}
