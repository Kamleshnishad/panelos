<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The bell for quiet leads (LEADS_PLAN 5.6), refreshed by the hourly `notifications:scan` as standing entries (see AppNotificationService::syncStanding):
 *  - each person gets ONE line for their quiet leads ("3 leads have had no activity for 7 days or more", with the oldest named), not one per lead;
 *  - quiet leads that belong to nobody are summarised the same way for the company admins.
 * The text carries the date the oldest lead was last touched and never "N days", so it turns unread only when the picture changes, not every morning.
 * It goes away by itself when the leads are looked after. 0 days in Settings -> Notifications switches it off.
 */
class LeadStaleService
{
    public const TYPE = 'lead_quiet';

    public function __construct(private AppNotificationService $notify)
    {
    }

    public function sync(int $companyId): void
    {
        $items = [];
        $adminItems = [];
        if (LeadEngagement::alertsOn($companyId)) {
            $days = LeadEngagement::staleDays($companyId);
            $quiet = LeadEngagement::select(LeadEngagement::onlyQuiet(Lead::withoutGlobalScope('tenant')->where('leads.company_id', $companyId), $companyId))
                ->orderByRaw('coalesce(last_touch_at, leads.created_at)')->limit(2000)->get();

            foreach ($quiet->groupBy(fn (Lead $l) => $l->assigned_to_user_id ?? 0) as $userId => $group) {
                $oldest = $group->first();
                $touched = ($oldest->last_touch_at ?? $oldest->created_at)->copy()->timezone('Asia/Kolkata')->format('j M');
                $n = $group->count();
                $what = $userId ? '' : 'unassigned ';
                $item = [
                    'title'    => "{$n} {$what}lead" . ($n === 1 ? ' has' : 's have') . " had no activity for {$days} days or more",
                    'body'     => "Oldest: {$oldest->lead_no} ({$oldest->contact_name}), last touched {$touched}. Open Leads and choose Quiet leads.",
                    'section'  => 'leads',
                    'severity' => 'warning',
                ];
                $userId ? $items["quiet:{$userId}"] = $item + ['to' => [(int) $userId]] : $adminItems['quiet:unassigned'] = $item;
            }
        }
        $this->notify->syncStanding($companyId, [], self::TYPE, $items + $this->forAdmins($companyId, $adminItems));
    }

    /** The unassigned summary goes to every active company admin, keyed per admin so each entry is that person's own. @return array<string,array> */
    private function forAdmins(int $companyId, array $adminItems): array
    {
        if (!$adminItems) {
            return [];
        }
        $ids = User::withoutGlobalScope('tenant')->where('company_id', $companyId)->where('is_active', true)->where('is_company_admin', true)->pluck('id')->all();
        $item = $adminItems['quiet:unassigned'];
        $out = [];
        foreach ($ids as $id) {
            $out["quiet:unassigned:{$id}"] = $item + ['to' => [(int) $id]];
        }

        return $out;
    }
}
