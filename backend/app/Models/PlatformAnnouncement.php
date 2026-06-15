<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformAnnouncement extends Model
{
    protected $fillable = ['message', 'type', 'is_active', 'starts_at', 'ends_at'];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
    ];

    /** Currently-live announcements shown to all tenants. */
    public static function live()
    {
        $now = now();
        return static::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->latest('id')->get();
    }
}
