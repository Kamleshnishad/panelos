<?php

namespace App\Console\Commands;

use App\Services\MonthlyMisService;
use Illuminate\Console\Command;

/** Mails last month's MIS to the admins of every company that switched it on (FR-010). Runs on the 1st; safe to re-run, a month is never sent twice. */
class SendMonthlyMis extends Command
{
    protected $signature = 'reports:send-monthly-mis {--month= : YYYY-MM to send for (default: last month)} {--company= : only this company id} {--force : send even if that month was already sent}';

    protected $description = 'E-mail the monthly MIS to the admins of companies that switched it on';

    public function handle(MonthlyMisService $service): int
    {
        $month = $this->option('month');
        if ($month !== null && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $month)) {
            $this->error('--month must look like 2026-10.');

            return self::FAILURE;
        }

        $results = $service->run($month ?: null, $this->option('company') ? (int) $this->option('company') : null, (bool) $this->option('force'));
        foreach ($results as $r) {
            $this->line("company {$r['company_id']}: {$r['status']}. {$r['detail']}");
        }
        $this->info(count($results) . ' company(ies) had the monthly MIS switched on.');

        return collect($results)->contains('status', 'failed') ? self::FAILURE : self::SUCCESS;
    }
}
