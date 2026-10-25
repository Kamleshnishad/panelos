<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| These used to live in app/Console/Kernel.php, which Laravel 11 does not
| load — so `schedule:list` reported "No scheduled tasks have been defined"
| and supervisord's `schedule:run` loop ran every 60s doing nothing. The
| visible damage: no nightly database backup (dumps only happened when
| someone deployed) and no automated payment or SMS reminders at all. See
| PB-030. Keep schedules HERE; adding a Console\Kernel back will not work.
|
| `onOneServer()` is deliberately omitted — production is a single container,
| so it would only add a lock dependency for no benefit. `withoutOverlapping`
| is kept: a slow backup must not have the next one start on top of it.
*/

// Payment reminders for overdue invoices.
Schedule::command('reminders:send')
    ->dailyAt('09:00')
    ->withoutOverlapping(10)
    ->runInBackground();

// SMS reminders for invoices 7+ days overdue.
Schedule::command('sms:send-reminders --days_overdue=7')
    ->dailyAt('10:00')
    ->withoutOverlapping(10)
    ->runInBackground();

// Prune reminder rows that have been settled.
Schedule::command('reminders:cleanup')
    ->weekly()
    ->withoutOverlapping(10);

// Bell notifications for standing conditions (low stock, overdue invoices).
Schedule::command('notifications:scan')
    ->hourly()
    ->withoutOverlapping(10);

// IndiaMART enquiries into Leads every ten minutes (they ask for 10 to 15, and refuse more than one request in 5). Only companies that switched it on.
Schedule::command('leads:pull-indiamart')
    ->everyTenMinutes()
    ->withoutOverlapping(9);

// Nightly database backup, retaining the last 14 dumps.
Schedule::command('db:backup --keep=14')
    ->dailyAt('01:30')
    ->withoutOverlapping(30)
    ->runInBackground();

// Refresh now leaves the old token alive for a short grace window instead of
// deleting it (see AuthController::REFRESH_GRACE_SECONDS), so expired token rows
// accumulate — one per refresh, per user. Sweep them daily.
Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('02:15')
    ->withoutOverlapping(10);

// One beat a minute: /api/health reports the scheduler as STALE when these stop (backups and reminders ride on it).
Schedule::call(fn () => \App\Support\SystemHealth::heartbeat())->everyMinute()->name('health-heartbeat');
