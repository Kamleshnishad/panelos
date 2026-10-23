<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Regression for PB-030.
 *
 * The schedule lived in app/Console/Kernel.php, a Laravel 10 artifact that
 * Laravel 11 never loads. Nothing failed loudly: `schedule:run` ran every 60
 * seconds under supervisord and did nothing, so there was no nightly database
 * backup (dumps only happened at deploy time) and no automated payment or SMS
 * reminders — for as long as the app had been on Laravel 11.
 *
 * This asserts the tasks are actually registered, so the same silent loss
 * cannot happen again on the next framework upgrade.
 */
class ScheduledTasksTest extends TestCase
{
    /** @return array<int,string> every registered scheduled command */
    private function scheduledCommands(): array
    {
        return collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command ?? '')
            ->filter()
            ->values()
            ->all();
    }

    public function test_the_nightly_database_backup_is_scheduled(): void
    {
        $this->assertTrue(
            $this->hasScheduled('db:backup'),
            'No nightly db:backup is scheduled — production would have no routine backup.'
        );
    }

    public function test_reminder_tasks_are_scheduled(): void
    {
        $this->assertTrue($this->hasScheduled('reminders:send'), 'Payment reminders are not scheduled.');
        $this->assertTrue($this->hasScheduled('sms:send-reminders'), 'SMS reminders are not scheduled.');
        $this->assertTrue($this->hasScheduled('reminders:cleanup'), 'Reminder cleanup is not scheduled.');
        $this->assertTrue($this->hasScheduled('sanctum:prune-expired'), 'Expired API tokens are never pruned.');
    }

    public function test_every_scheduled_command_actually_exists(): void
    {
        $registered = array_keys(\Illuminate\Support\Facades\Artisan::all());

        foreach ($this->scheduledCommands() as $command) {
            // e.g. "'php' 'artisan' sms:send-reminders --days_overdue=7" → sms:send-reminders
            preg_match('/artisan.?\s+([a-z0-9:_-]+)/i', $command, $m);
            if (!isset($m[1])) continue;

            $this->assertContains(
                $m[1],
                $registered,
                "Scheduled command '{$m[1]}' does not exist — it would fail silently every run."
            );
        }
    }

    private function hasScheduled(string $needle): bool
    {
        foreach ($this->scheduledCommands() as $command) {
            if (str_contains($command, $needle)) return true;
        }
        return false;
    }
}
