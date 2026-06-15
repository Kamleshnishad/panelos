<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BaseModel extends Model
{
    use SoftDeletes;
    use BelongsToTenant;   // global tenant scope + company_id auto-fill on create

    protected $fillable = [];

    /**
     * Scope query to current company (kept for backward compatibility; the
     * BelongsToTenant global scope now does this automatically on every read).
     */
    public function scopeCompany($query)
    {
        if (auth()->check() && auth()->user() && !auth()->user()->is_super_admin) {
            return $query->where('company_id', auth()->user()->company_id);
        }
        return $query;
    }

    /**
     * Get company relationship
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
