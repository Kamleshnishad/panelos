<?php

namespace App\Models;

/** One next action on a lead (LEADS_PLAN 5.2): what, when and who. */
class LeadTask extends BaseModel
{
    public const OPEN = 'open';
    public const DONE = 'done';
    public const CANCELLED = 'cancelled';

    /** key => label */
    public const TYPES = ['call' => 'Call', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'visit' => 'Site visit', 'quote' => 'Send quotation', 'other' => 'Other'];

    protected $fillable = [
        'company_id', 'lead_id', 'assigned_to_user_id', 'created_by_user_id', 'type', 'title', 'due_date', 'due_time', 'status', 'done_at', 'done_by_user_id', 'done_note',
    ];

    protected $casts = ['due_date' => 'date', 'done_at' => 'datetime'];

    public function lead() { return $this->belongsTo(Lead::class); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to_user_id'); }

    public function label(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    /** "12 Nov" or "12 Nov, 2:30 pm". */
    public function when(): string
    {
        $d = $this->due_date->format('j M');

        return $this->due_time ? $d . ', ' . \Illuminate\Support\Carbon::parse($this->due_time)->format('g:i a') : $d;
    }
}
