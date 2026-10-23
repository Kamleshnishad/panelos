<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'company_id', 'user_id', 'type', 'severity', 'title', 'body',
        'link_section', 'link_id', 'dedupe_key', 'read_at',
    ];

    protected $casts = ['read_at' => 'datetime'];
}
