<?php

namespace App\Console\Commands;

use App\Services\IndiaMartPullService;
use Illuminate\Console\Command;

/** LEADS_PLAN 3.2: every 10 minutes, bring in each switched-on company's new IndiaMART enquiries. One company's trouble never stops the others. */
class PullIndiaMartLeads extends Command
{
    protected $signature = 'leads:pull-indiamart {--company= : Only this company id}';
    protected $description = 'Pull new IndiaMART enquiries into Leads for every company that has it switched on';

    public function handle(IndiaMartPullService $pull): int
    {
        foreach ($pull->due($this->option('company') ? (int) $this->option('company') : null) as $connector) {
            try {
                $r = $pull->pullSinceLast($connector);
                $this->line("company {$connector->company_id}: {$r['status']}: {$r['message']}");
            } catch (\Throwable $e) {
                report($e);
                $this->error("company {$connector->company_id}: failed");
            }
        }

        return self::SUCCESS;
    }
}
