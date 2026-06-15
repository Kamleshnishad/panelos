<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = ['code', 'type', 'value', 'max_uses', 'used_count', 'expires_at', 'is_active'];

    protected $casts = [
        'value'      => 'decimal:2',
        'is_active'  => 'boolean',
        'expires_at' => 'date',
    ];

    /** Find a usable coupon by code (active, not expired, uses left). */
    public static function usable(string $code): ?self
    {
        $c = static::whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])->first();
        if (!$c || !$c->is_active) return null;
        if ($c->expires_at && $c->expires_at->isPast()) return null;
        if ($c->max_uses !== null && $c->used_count >= $c->max_uses) return null;
        return $c;
    }

    /** Discounted amount (never below 0). */
    public function apply(float $amount): float
    {
        $off = $this->type === 'percent' ? $amount * ((float) $this->value) / 100 : (float) $this->value;
        return max(0, round($amount - $off, 2));
    }
}
