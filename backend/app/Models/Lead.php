<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends BaseModel
{
    use \App\Traits\Auditable;
    use SoftDeletes;

    /** Still being worked. */
    public const OPEN = ['new', 'contacted', 'qualified', 'quoted'];

    /** Finished. A closed lead leaves the pipeline; it can only come back through a reopen (with a reason). */
    public const CLOSED = ['won', 'lost', 'invalid'];

    /** `invalid` is junk (spam, wrong number, not a buyer): kept for the record, left out of every statistic. `on_hold` waits for a date. */
    public const STATUSES = ['new', 'contacted', 'qualified', 'quoted', 'on_hold', 'won', 'lost', 'invalid'];

    protected $fillable = [
        'company_id', 'lead_no', 'contact_name', 'company_name', 'phone', 'email', 'city',
        'source', 'requirement', 'application', 'est_qty_sqm', 'est_value', 'status',
        'assigned_to_user_id', 'next_follow_up_date', 'lost_reason', 'lost_reason_id', 'lost_note', 'customer_id', 'quotation_id', 'notes',
    ];

    protected $casts = [
        'est_qty_sqm'         => 'decimal:2',
        'est_value'           => 'decimal:2',
        'next_follow_up_date' => 'date',
    ];

    /**
     * The one table of allowed moves (LEADS_PLAN 1.2):
     *  - an open lead can move to any other open stage, or be put on hold, won, lost or invalid;
     *  - a lead on hold can go back to any open stage, or be won, lost or invalid;
     *  - a closed lead can only be reopened to an open stage. Won to lost (or the other way) goes through a reopen.
     *
     * @return list<string>
     */
    public static function allowedNext(string $from): array
    {
        if (in_array($from, self::OPEN, true)) {
            return array_values(array_diff([...self::OPEN, 'on_hold', 'won', 'lost', 'invalid'], [$from]));
        }
        if ($from === 'on_hold') {
            return [...self::OPEN, 'won', 'lost', 'invalid'];
        }

        return self::OPEN;
    }

    /** @return array<string,list<string>> every status with the statuses it may move to, for the screen */
    public static function transitions(): array
    {
        $map = [];
        foreach (self::STATUSES as $status) {
            $map[$status] = self::allowedNext($status);
        }

        return $map;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED, true);
    }

    protected static function boot()
    {
        parent::boot();
        // phone_key / email_key are what duplicate detection looks up (LeadMatcher); they follow phone and email on every save.
        static::saving(function (Lead $lead) {
            $lead->phone_key = \App\Services\LeadMatcher::phoneKey($lead->phone);
            $lead->email_key = \App\Services\LeadMatcher::emailKey($lead->email);
        });
    }

    public function company()      { return $this->belongsTo(Company::class); }
    public function assignedUser() { return $this->belongsTo(User::class, 'assigned_to_user_id'); }
    public function customer()     { return $this->belongsTo(Customer::class); }
    public function quotation()    { return $this->belongsTo(Quotation::class); }
    public function activities()   { return $this->hasMany(LeadActivity::class)->orderByDesc('activity_date')->orderByDesc('id'); }
}
