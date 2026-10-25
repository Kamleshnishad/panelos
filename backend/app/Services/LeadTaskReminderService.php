<?php

namespace App\Services;

use App\Models\LeadTask;
use App\Models\NotificationSetting;
use Illuminate\Support\Carbon;

/**
 * Bell entries for next actions (LEADS_PLAN 5.2), refreshed by the hourly `notifications:scan` like the follow-up reminders of 1.7: one standing entry
 * per open action that is due today or earlier, for the person it is assigned to. It says "due today" (with the time if there is one), and turns
 * "overdue" (unread again, red) the day after; it goes away by itself when the action is done, moved or removed. The same switch as the lead
 * follow-up reminders (Settings -> Notifications) turns these off. The scan is hourly, so a time-of-day is shown but the bell rings within the hour.
 * Leads that have open actions are left out of the plain follow-up reminders (LeadReminderService), so nobody is told twice about the same thing.
 */
class LeadTaskReminderService
{
    public const TYPE = 'lead_task';

    public function __construct(private AppNotificationService $notify)
    {
    }

    public function sync(int $companyId): void
    {
        $settings = NotificationSetting::withoutGlobalScope('tenant')->where('company_id', $companyId)->first();
        $on = $settings?->notify_lead_followups ?? true;
        $today = LeadTaskService::today();
        $items = [];

        if ($on) {
            $due = LeadTask::withoutGlobalScope('tenant')->where('company_id', $companyId)->where('status', LeadTask::OPEN)->whereNotNull('assigned_to_user_id')
                ->whereDate('due_date', '<=', $today)->with(['lead' => fn ($q) => $q->withoutGlobalScope('tenant')])->orderBy('due_date')->get();
            foreach ($due as $t) {
                $lead = $t->lead;
                if (!$lead || $lead->isClosed()) {
                    continue;
                }
                $isToday = $t->due_date->toDateString() === $today->toDateString();
                $items["task:{$t->id}"] = [
                    'to'       => [$t->assigned_to_user_id],
                    'title'    => ($isToday ? ($t->due_time ? 'Due today at ' . Carbon::parse($t->due_time)->format('g:i a') : 'Due today') : 'Overdue') . ": {$t->label()} {$lead->lead_no} {$lead->contact_name}",
                    // The date, not "N days late", so the entry only turns unread once (today -> overdue), not every morning.
                    'body'     => ($isToday ? '' : 'Was due ' . $t->when() . '. ') . ($t->title ?: ($lead->requirement ? mb_substr($lead->requirement, 0, 120) : '')),
                    'section'  => 'leads',
                    'link_id'  => $lead->id,
                    'severity' => $isToday ? 'warning' : 'danger',
                ];
            }
        }
        $this->notify->syncStanding($companyId, [], self::TYPE, $items);
    }
}
