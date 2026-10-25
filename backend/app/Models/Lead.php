<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends BaseModel
{
    use \App\Traits\Auditable;
    use SoftDeletes;

    /** The STARTER open stages. A company edits its own (LeadStages); code must ask there, not here. Kept for the defaults and for tests that name them. */
    public const OPEN = ['new', 'contacted', 'qualified', 'quoted'];

    /** Finished. A closed lead leaves the pipeline; it can only come back through a reopen (with a reason). */
    public const CLOSED = ['won', 'lost', 'invalid'];

    /** `invalid` is junk (spam, wrong number, not a buyer): kept for the record, left out of every statistic. `on_hold` waits for a date. */
    public const STATUSES = ['new', 'contacted', 'qualified', 'quoted', 'on_hold', 'won', 'lost', 'invalid'];

    protected $fillable = [
        'company_id', 'lead_no', 'contact_name', 'company_name', 'phone', 'email', 'city',
        'source', 'external_id', 'campaign', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_page', 'first_seen_at', 'requirement', 'application', 'est_qty_sqm', 'est_value', 'status',
        'assigned_to_user_id', 'next_follow_up_date', 'lost_reason', 'lost_reason_id', 'lost_note', 'customer_id', 'quotation_id', 'notes',
    ];

    protected $casts = [
        'est_qty_sqm'         => 'decimal:2',
        'est_value'           => 'decimal:2',
        'next_follow_up_date' => 'date',
        'first_seen_at'       => 'datetime',
        'first_response_at'   => 'datetime',      // only present when the query asked for it (LeadEngagement::select)
        'last_touch_at'       => 'datetime',
        'score'               => 'integer',      // only present when the query asked for it (LeadScoring::select)
    ];

    protected $appends = ['quiet_days', 'response_minutes', 'response_waiting_minutes', 'temperature'];

    /** hot, warm or cold from the score; null for a closed lead or when the score was not loaded. */
    public function getTemperatureAttribute(): ?string
    {
        return \App\Services\LeadScoring::temperature(array_key_exists('score', $this->attributes) && $this->attributes['score'] !== null ? (int) $this->attributes['score'] : null);
    }

    /** Days since a person last touched this open lead; null when the lead is closed or on hold, or the engagement columns were not loaded. */
    public function getQuietDaysAttribute(): ?int
    {
        if (!array_key_exists('last_touch_at', $this->attributes) || !$this->isOpen()) {
            return null;
        }

        return max(0, (int) ($this->last_touch_at ?? $this->created_at)->diffInDays(now()));
    }

    /** The moment the lead counts as having arrived: the buyer's own time when it is within 6 hours before it entered PanelOS, else when it entered. */
    private function arrivedAt()
    {
        return $this->first_seen_at && $this->first_seen_at->gte($this->created_at->copy()->subHours(6)) ? $this->first_seen_at : $this->created_at;
    }

    /** Minutes from arriving to the first response by a person; null when nobody has responded, or the engagement columns were not loaded. */
    public function getResponseMinutesAttribute(): ?int
    {
        if (!array_key_exists('first_response_at', $this->attributes) || $this->first_response_at === null) {
            return null;
        }

        return max(0, (int) $this->arrivedAt()->diffInMinutes($this->first_response_at));
    }

    /** Minutes an open lead has waited for its first response so far; null once answered, when closed or on hold, or when not loaded. */
    public function getResponseWaitingMinutesAttribute(): ?int
    {
        if (!array_key_exists('first_response_at', $this->attributes) || $this->first_response_at !== null || !$this->isOpen()) {
            return null;
        }

        return max(0, (int) $this->arrivedAt()->diffInMinutes(now()));
    }

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

    /** In one of the company's open stages (not on hold, not closed). */
    public function isOpen(): bool
    {
        return \App\Services\LeadStages::for((int) $this->company_id)->isOpen($this->status);
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
    public function payloads()     { return $this->hasMany(LeadPayload::class)->orderByDesc('received_at')->orderByDesc('id'); }
    public function activities()   { return $this->hasMany(LeadActivity::class)->orderByDesc('activity_date')->orderByDesc('id'); }
}
