<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        // Send payment reminders daily at 9 AM
        $schedule->command('reminders:send')
            ->dailyAt('09:00')
            ->onOneServer()
            ->withoutOverlapping(10)
            ->runInBackground();

        // Send SMS reminders daily at 10 AM (for invoices 7+ days overdue)
        $schedule->command('sms:send-reminders --days_overdue=7')
            ->dailyAt('10:00')
            ->onOneServer()
            ->withoutOverlapping(10)
            ->runInBackground();

        // Clean up old reminders weekly
        $schedule->command('reminders:cleanup')
            ->weekly()
            ->onOneServer()
            ->withoutOverlapping(10);
    }

    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
