<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * LEADS_PLAN 1.7: a follow-up date that actually tells someone. Run hourly by `notifications:scan`; every notification is a
 * "standing" one (see AppNotificationService::syncStanding), so running it again changes nothing, and it disappears by itself
 * once the lead is followed up, won, lost or marked invalid.
 *
 *  - The lead's assignee gets one bell entry per lead: "due today", which becomes "overdue" (unread again, red) the day after.
 *  - Company admins get one summary for leads overdue for more than N days (settings, default 3), unassigned leads included.
 *
 * A lead on hold is reminded on its return date, which is what the hold date is for. Won, lost and invalid leads never are.
 * Switch off in Settings -> Notifications. WhatsApp and e-mail reminders are not built yet (they wait for the client's answer).
 */
class LeadReminderService
{
    public const TYPE = 'lead_follow_up';
    public const DIGEST_TYPE = 'lead_follow_up_digest';

    public function __construct(private AppNotificationService $notify)
    {
    }

    public function sync(int $companyId): void
    {
        $settings = NotificationSetting::withoutGlobalScope('tenant')->where('company_id', $companyId)->first();
        $on = $settings?->notify_lead_followups ?? true;
        $days = max(1, (int) ($settings?->lead_overdue_alert_days ?: 3));
        $today = Carbon::today();

        $leads = $on ? Lead::withoutGlobalScope('tenant')->where('company_id', $companyId)
            ->whereNotIn('status', Lead::CLOSED)
            ->whereNotNull('next_follow_up_date')->whereDate('next_follow_up_date', '<=', $today)
            ->orderBy('next_follow_up_date')->get() : collect();

        // A lead with open next actions is reminded action by action (LeadTaskReminderService), not twice.
        $withActions = \App\Models\LeadTask::withoutGlobalScope('tenant')->where('company_id', $companyId)->where('status', 'open')->pluck('lead_id')->flip();

        $mine = [];
        foreach ($leads->whereNotNull('assigned_to_user_id')->reject(fn ($l) => $withActions->has($l->id)) as $l) {
            $due = $l->next_follow_up_date->copy()->startOfDay();
            $name = $l->contact_name . ($l->company_name ? ", {$l->company_name}" : '');
            $mine["lead:{$l->id}:{$l->assigned_to_user_id}"] = [
                'to'       => [$l->assigned_to_user_id],
                'title'    => $due->isSameDay($today) ? "Follow up today: {$l->lead_no}" : "Overdue follow-up: {$l->lead_no}",
                // The date, not "N days", so the entry only turns unread once (today -> overdue), not every morning.
                'body'     => $due->isSameDay($today) ? "{$name}. Follow-up is due today." : "{$name}. Follow-up was due on " . $due->format('j M') . '.',
                'section'  => 'leads',
                'link_id'  => $l->id,
                'severity' => $due->isSameDay($today) ? 'warning' : 'danger',
            ];
        }
        $this->notify->syncStanding($companyId, [], self::TYPE, $mine);

        $late = $leads->filter(fn (Lead $l) => $l->next_follow_up_date->copy()->startOfDay()->lt($today->copy()->subDays($days)));
        $digest = [];
        if ($late->isNotEmpty()) {
            $oldest = $late->first();
            $n = $late->count();
            $digest['digest'] = [
                'title'    => "{$n} lead" . ($n === 1 ? '' : 's') . " overdue for more than {$days} days",
                'body'     => "Oldest: {$oldest->lead_no} ({$oldest->contact_name}), due " . $oldest->next_follow_up_date->format('j M') . '. Open Leads to reassign or close them.',
                'section'  => 'leads',
                'severity' => 'danger',
            ];
        }
        $admins = User::withoutGlobalScope('tenant')->where('company_id', $companyId)->where('is_active', true)->where('is_company_admin', true)->pluck('id')->all();
        $this->notify->syncStanding($companyId, $admins, self::DIGEST_TYPE, $digest);
    }
}
