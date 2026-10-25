<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadTask;
use App\Models\NotificationSetting;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Chasing a sent quotation (LEADS_PLAN 5.5): a quotation that has gone out and gets no answer is the commonest way a sale is lost, so the lead gets next
 * actions (LeadTaskService) at +3 days, +7 days and two days before the quotation expires. Run by the hourly `notifications:scan`.
 *
 * It only READS quotations (status `sent`, `sent_at`, `valid_until`); nothing in the quotation module is changed or hooked, so it cannot get in the way of
 * sending, revising or ordering. The "sent" moment is simply seen on the next scan, within the hour.
 *
 *  - Which lead: the one whose quotation is this one or an earlier version of it (a revised quotation is chased for the same lead). Closed leads are left alone.
 *  - Who: the lead's owner, else the company's first active admin. Planning them does not ring a bell (three at once would be noise); each rings on its own day
 *    through the ordinary action reminders.
 *  - Steps in the past are not planned (a quotation sent 5 days ago gets the +7 and the expiry steps, not a +3 that is already late); steps after the quotation has
 *    expired are not planned; the expiry step is not planned when it would fall on or before the +7 step.
 *  - A step is planned once. The (quotation, step) pair is unique and survives the action being done or removed, so finishing a chase by hand never brings it back.
 *  - When the quotation is accepted, rejected, expired or revised (it is no longer `sent`), its open chase actions are cancelled and the lead's date follows what is left.
 * The company can switch it off in Settings -> Notifications ("Chase sent quotations"); that stops new steps being planned.
 */
class QuotationChaseService
{
    /** step => [kind of action, days after it was sent] ; the expiry step is worked out from valid_until */
    private const STEPS = ['d3' => ['call', 3], 'd7' => ['whatsapp', 7]];

    public function __construct(private LeadService $leads, private LeadTaskService $tasks)
    {
    }

    public function sync(int $companyId): void
    {
        $this->cancelStale($companyId);

        $on = NotificationSetting::withoutGlobalScope('tenant')->where('company_id', $companyId)->value('chase_quotations');
        if ($on === false || $on === 0 || $on === '0') {
            return;
        }
        $today = LeadTaskService::today();

        Quotation::withoutGlobalScope('tenant')->where('company_id', $companyId)->where('status', 'sent')->whereNotNull('sent_at')->orderBy('id')->each(function (Quotation $q) use ($companyId, $today) {
            $lead = $this->leads->leadsForQuotation($companyId, $q->id)->first(fn (Lead $l) => !$l->isClosed());
            if (!$lead) {
                return;
            }
            foreach ($this->plan($q) as $step => $d) {
                if ($d['date']->lt($today) || $this->planned($q->id, $step)) {
                    continue;
                }
                try {
                    $this->tasks->create($lead, [
                        'type' => $d['type'], 'due_date' => $d['date']->toDateString(), 'title' => $d['title'],
                        'assigned_to_user_id' => $lead->assigned_to_user_id ?: $this->fallbackPerson($companyId),
                    ], null, ['quotation_id' => $q->id, 'chase_step' => $step, 'quiet' => true]);
                } catch (QueryException $e) {
                    // Two scans at the same moment: the unique (quotation, step) key stopped the second. Anything else is a real problem.
                    if (!str_contains($e->getMessage(), 'lead_tasks_chase_unique')) {
                        throw $e;
                    }
                }
            }
        });
    }

    /** @return array<string, array{type:string, date:Carbon, title:string}> the steps this quotation should have, with their days (Indian time) */
    public function plan(Quotation $q): array
    {
        $sent = Carbon::parse($q->sent_at)->timezone('Asia/Kolkata')->startOfDay();
        $until = $q->valid_until ? Carbon::parse($q->valid_until->toDateString(), 'Asia/Kolkata')->startOfDay() : null;
        $no = $q->quotation_no;
        $steps = [];

        foreach (self::STEPS as $step => [$type, $days]) {
            $date = $sent->copy()->addDays($days);
            if ($until && $date->gt($until)) {
                continue;                                        // the quotation has expired by then: nothing to chase
            }
            $steps[$step] = ['type' => $type, 'date' => $date, 'title' => "Follow up on quotation {$no}: " . ($days === 3 ? 'sent 3 days ago, any questions?' : 'sent a week ago, has the buyer decided?')];
        }
        if ($until) {
            $date = $until->copy()->subDays(2);
            $latest = collect($steps)->max(fn ($s) => $s['date']->timestamp);
            if ($date->gt($sent) && ($latest === null || $date->timestamp > $latest)) {
                $steps['expiry'] = ['type' => 'call', 'date' => $date, 'title' => "Quotation {$no} is valid until " . $until->format('j M') . ': ask for a decision'];
            }
        }

        return $steps;
    }

    private function planned(int $quotationId, string $step): bool
    {
        return LeadTask::withoutGlobalScope('tenant')->withTrashed()->where('quotation_id', $quotationId)->where('chase_step', $step)->exists();
    }

    private function fallbackPerson(int $companyId): ?int
    {
        return User::withoutGlobalScope('tenant')->where('company_id', $companyId)->where('is_active', true)->where('is_company_admin', true)->orderBy('id')->value('id');
    }

    /** Open chase actions whose quotation is no longer out with the buyer. */
    private function cancelStale(int $companyId): void
    {
        $ids = LeadTask::withoutGlobalScope('tenant')->where('lead_tasks.company_id', $companyId)->where('lead_tasks.status', LeadTask::OPEN)->whereNotNull('chase_step')
            ->join('quotations', 'quotations.id', '=', 'lead_tasks.quotation_id')->where('quotations.status', '!=', 'sent')
            ->select('lead_tasks.quotation_id', 'quotations.status')->distinct()->get();
        foreach ($ids as $row) {
            $this->tasks->cancelForQuotation($companyId, (int) $row->quotation_id, 'the quotation is now ' . str_replace('_', ' ', (string) $row->status) . '.');
        }
    }
}
