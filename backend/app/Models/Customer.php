<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Customer extends BaseModel
{
    use \App\Traits\Auditable;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'type',
        'contact_person',
        'email',
        'phone',
        'whatsapp_no',
        'gstin',
        'pan',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'state_code',
        'pincode',
        'country',
        'credit_limit',
        'outstanding_balance',
        'payment_terms_days',
        'notes',
        'is_active',
    ];

    /**
     * The next free customer code for a company: the first four letters of the name plus a number that counts up (RAVI101, RAVI102...).
     * It used to be the letters plus a random 3-digit number, which collides after a few hundred customers and then fails on the
     * unique (company_id, code) index. Callers should still retry once on a unique-key error: two requests can ask at the same moment.
     */
    public static function nextCode(int $companyId, string $name): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 4)) ?: 'CUST';
        $max = 100;
        foreach (static::withoutGlobalScope('tenant')->withTrashed()->where('company_id', $companyId)->where('code', 'like', $prefix . '%')->pluck('code') as $code) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $prefix . ($max + 1);
    }

    protected $appends = ['derived_state_code'];

    protected $casts = [
        'credit_limit'        => 'decimal:2',
        'outstanding_balance' => 'decimal:2',
        'is_active'           => 'boolean',
    ];

    /**
     * Store state_code in one canonical format: the 2-digit GST code.
     * The form offers "GJ" while GSTIN-derived data is "24"; keeping both in the
     * column made intra/inter-state GST a coin flip. Unrecognised values are
     * stored as given rather than discarded.
     */
    public function setStateCodeAttribute($value): void
    {
        $this->attributes['state_code'] = \App\Support\GstState::normalize($value) ?? $value;
    }

    // Returns the best available 2-char alpha state code for inter-state detection.
    // Priority: explicit state_code column → GSTIN first 2 digits → first 2 chars of state field.
    public function getDerivedStateCodeAttribute(): string
    {
        if (!empty($this->state_code)) {
            return strtoupper($this->state_code);
        }
        if ($this->gstin && strlen($this->gstin) >= 2) {
            $code = $this->alphaStateFromGstinPrefix(substr($this->gstin, 0, 2));
            if ($code) return $code;
        }
        return strtoupper(substr($this->state ?? '', 0, 2));
    }

    private function alphaStateFromGstinPrefix(string $num): string
    {
        $map = [
            '01'=>'JK','02'=>'HP','03'=>'PB','04'=>'CH','05'=>'UT','06'=>'HR','07'=>'DL',
            '08'=>'RJ','09'=>'UP','10'=>'BR','11'=>'SK','12'=>'AR','13'=>'NL','14'=>'MN',
            '15'=>'MZ','16'=>'TR','17'=>'ML','18'=>'AS','19'=>'WB','20'=>'JH','21'=>'OD',
            '22'=>'CG','23'=>'MP','24'=>'GJ','25'=>'DD','26'=>'DN','27'=>'MH','28'=>'AP',
            '29'=>'KA','30'=>'GA','31'=>'LD','32'=>'KL','33'=>'TN','34'=>'PY','35'=>'AN',
            '36'=>'TG','37'=>'AP','38'=>'LA',
        ];
        return $map[$num] ?? '';
    }

    public function quotations() { return $this->hasMany(Quotation::class); }
    public function orders()     { return $this->hasMany(Order::class); }
    public function invoices()   { return $this->hasMany(Invoice::class); }
    public function dispatches() { return $this->hasMany(Dispatch::class); }

    public function scopeActive($query)       { return $query->where('is_active', true); }
    public function scopeByName($query, $name){ return $query->where('name', 'like', "%{$name}%"); }
}
