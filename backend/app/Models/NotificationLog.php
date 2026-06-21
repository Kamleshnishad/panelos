<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per WhatsApp/SMS send attempt (OPS-H3). company_id is set explicitly
 * by the dispatcher (which can run outside an authenticated request), so no
 * tenant global scope here — reads filter by company_id manually.
 */
class NotificationLog extends Model
{
    protected $table = 'notification_logs';
    public $timestamps = false;

    protected $fillable = [
        'company_id', 'channel', 'recipient', 'type', 'status', 'error', 'created_at',
    ];
}
