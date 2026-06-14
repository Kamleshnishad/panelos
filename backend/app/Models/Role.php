<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'guard_name',
        'permissions',
        'description',
        'is_system_role',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_system_role' => 'boolean',
        ];
    }

    /**
     * Get company relationship
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get users relationship
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }
}
