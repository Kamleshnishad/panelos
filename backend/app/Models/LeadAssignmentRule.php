<?php

namespace App\Models;

/** "Leads like this go to these people, taking turns" (LEADS_PLAN 2.4). Conditions left empty match anything. */
class LeadAssignmentRule extends BaseModel
{
    protected $fillable = ['company_id', 'name', 'priority', 'is_active', 'source', 'city', 'application', 'user_ids', 'last_user_id'];

    protected $casts = [
        'is_active' => 'boolean',
        'user_ids'  => 'array',
        'priority'  => 'integer',
    ];

    /** Does this rule apply to a lead with this source, city and application? Case does not matter; an empty condition matches anything. */
    public function matches(?string $source, ?string $city, ?string $application): bool
    {
        $same = fn (?string $want, ?string $have) => $want === null || trim($want) === '' || mb_strtolower(trim($want)) === mb_strtolower(trim((string) $have));

        return $same($this->source, $source) && $same($this->city, $city) && $same($this->application, $application);
    }
}
