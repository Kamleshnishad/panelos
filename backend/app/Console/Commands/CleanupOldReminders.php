<?php

namespace App\Console\Commands;

use App\Models\PaymentReminder;
use Illuminate\Console\Command;

class CleanupOldReminders extends Command
{
    protected $signature = 'reminders:cleanup {--days=30}';

    protected $description = 'Clean up old paid reminders older than specified days';

    public function handle()
    {
        $days = $this->option('days');
        $deletedCount = PaymentReminder::where('is_paid', true)
            ->where('updated_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Cleaned up {$deletedCount} old payment reminders");

        return 0;
    }
}
