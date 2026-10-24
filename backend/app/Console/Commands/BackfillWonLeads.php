<?php

namespace App\Console\Commands;

use App\Services\LeadService;
use Illuminate\Console\Command;

/** LEADS_PLAN 1.3: mark leads won when an order already exists for their quotation. Lists first; changes nothing without --apply. */
class BackfillWonLeads extends Command
{
    protected $signature = 'leads:backfill-won {--company= : Only this company id} {--apply : Really change the leads (without it, only list them)}';
    protected $description = 'List (or, with --apply, mark) leads that should be won because an order exists for their quotation';

    public function handle(LeadService $leads): int
    {
        $apply = (bool) $this->option('apply');
        $rows = $leads->backfillWon($this->option('company') ? (int) $this->option('company') : null, $apply);

        if (!$rows) {
            $this->info('No lead needs changing.');

            return self::SUCCESS;
        }

        $this->table(['Lead', 'Status', 'Order', 'Action'], array_map(fn ($r) => [$r['lead_no'], $r['status'], $r['order_no'], $r['action']], $rows));
        $this->line($apply ? 'Applied.' : 'Dry run: nothing was changed. Run again with --apply to mark them.');

        return self::SUCCESS;
    }
}
