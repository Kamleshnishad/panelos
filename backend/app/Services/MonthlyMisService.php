<?php

namespace App\Services;

use App\Mail\MonthlyMisMailable;
use App\Models\Company;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Support\CompanyMail;
use Carbon\Carbon;

/**
 * The monthly MIS e-mail (FR-010). On the 1st, every company that switched it on (Settings -> Notifications; off until someone does) gets last month's figures
 * mailed to its admins through ITS OWN mailbox. It is never sent through the server's mailer, never twice for the same month (`mis_last_sent_month`),
 * and a company with no mailbox, no admin with an e-mail address, or a failing mailbox is skipped and reported without stopping the others.
 */
class MonthlyMisService
{
    public function __construct(private ReportingService $reports)
    {
    }

    /** The calendar month before $now, as the first day of that month (in the business's time zone). */
    public static function lastMonth(?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now('Asia/Kolkata'))->copy()->startOfMonth()->subMonthNoOverflow();
    }

    /**
     * @param  string|null $month   "YYYY-MM" to send for (default: last month)
     * @param  int|null    $company only this company
     * @param  bool        $force   send even if that month was already sent
     * @return array<int,array{company_id:int, status:string, detail:string}>
     */
    public function run(?string $month = null, ?int $company = null, bool $force = false): array
    {
        $first = $month ? Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay() : self::lastMonth();
        $key = $first->format('Y-m');
        $out = [];

        $settings = NotificationSetting::withoutGlobalScope('tenant')->where('email_monthly_mis', true)->when($company, fn ($q) => $q->where('company_id', $company))->orderBy('company_id')->get();
        foreach ($settings as $s) {
            $out[] = ['company_id' => $s->company_id] + $this->sendFor($s, $first, $force);
        }

        return $out;
    }

    /** @return array{status:string, detail:string} */
    private function sendFor(NotificationSetting $s, Carbon $first, bool $force): array
    {
        $key = $first->format('Y-m');
        $cid = (int) $s->company_id;
        $company = Company::withoutGlobalScope('tenant')->find($cid);
        if (!$company || !$company->is_active) {
            return ['status' => 'skipped', 'detail' => 'The company is not active.'];
        }
        if (!$force && $s->mis_last_sent_month === $key) {
            return ['status' => 'skipped', 'detail' => "Already sent for {$key}."];
        }
        if (!CompanyMail::activeFor($cid, CompanyMail::kindFor($cid, 'monthly_mis'))) {
            return ['status' => 'skipped', 'detail' => 'No mailbox is set up for this company (Settings -> Customer Messaging).'];
        }
        $to = User::withoutGlobalScope('tenant')->where('company_id', $cid)->where('is_company_admin', true)->where('is_active', true)->whereNotNull('email')->where('email', '!=', '')->pluck('email')->unique()->values();
        if ($to->isEmpty()) {
            return ['status' => 'skipped', 'detail' => 'No active admin has an e-mail address.'];
        }

        $from = $first->toDateString();
        $until = $first->copy()->endOfMonth()->toDateString();
        $data = [
            'company'       => $company->legal_name ?: $company->name,
            'month_label'   => $first->format('F Y'),
            'mis'           => $this->reports->getMisReport($cid, $from, $until),
            'cash_received' => (float) $this->reports->getCashFlowStatement($cid, $from, $until)['operating_activities']['cash_collected'],
            'receivable'    => $this->reports->getAccountsReceivable($cid, $until, 1, 1)['summary'],
        ];

        $sent = 0;
        $failed = [];
        foreach ($to as $address) {
            try {
                CompanyMail::deliver($cid, $address, new MonthlyMisMailable($data), 'monthly_mis');
                $sent++;
            } catch (\Throwable $e) {
                $failed[] = $address;
                report($e);
            }
        }
        if ($sent === 0) {
            return ['status' => 'failed', 'detail' => 'The mailbox refused every message (' . implode(', ', $failed) . '). It will be tried again next time.'];
        }
        $s->forceFill(['mis_last_sent_month' => $key])->save();

        return ['status' => 'sent', 'detail' => "Sent to {$sent} admin(s) for {$key}" . ($failed ? '; failed for ' . implode(', ', $failed) : '') . '.'];
    }
}
