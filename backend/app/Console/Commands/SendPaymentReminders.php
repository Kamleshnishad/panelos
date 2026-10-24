<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\PaymentReminderService;
use Illuminate\Console\Command;

class SendPaymentReminders extends Command
{
    protected $signature = 'reminders:send {--company_id=}';

    protected $description = 'Send automated payment reminders for overdue invoices';

    public function handle()
    {
        $reminderService = app(PaymentReminderService::class);

        if ($this->option('company_id')) {
            $this->info('Sending reminders for company: ' . $this->option('company_id'));
            $result = \App\Models\NotificationSetting::emailRemindersEnabled((int) $this->option('company_id'))
                ? $reminderService->sendDueReminders($this->option('company_id'))
                : ['total' => 0, 'sent' => 0, 'failed' => 0, 'updated_schedule' => 0, 'skipped' => 1];
        } else {
            $this->info('Sending reminders for all companies...');

            $companies = Company::pluck('id');
            $totalResults = [
                'total' => 0,
                'sent' => 0,
                'failed' => 0,
                'updated_schedule' => 0,
            ];

            $skipped = 0;
            foreach ($companies as $companyId) {
                // Automatic customer e-mails are opt-in per company (Customer Messaging -> E-mail).
                if (!\App\Models\NotificationSetting::emailRemindersEnabled((int) $companyId)) {
                    $skipped++;
                    continue;
                }
                $result = $reminderService->sendDueReminders($companyId);
                $totalResults['total'] += $result['total'] ?? 0;
                $totalResults['sent'] += $result['sent'] ?? 0;
                $totalResults['failed'] += $result['failed'] ?? 0;
                $totalResults['updated_schedule'] += $result['updated_schedule'] ?? 0;
            }

            $totalResults['skipped'] = $skipped;
            $result = $totalResults;
        }

        $this->info('Payment Reminders Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Pending', $result['total'] ?? 0],
                ['Sent', $result['sent'] ?? 0],
                ['Failed', $result['failed'] ?? 0],
                ['Companies skipped (e-mail reminders switched off)', $result['skipped'] ?? 0],
                ['Updated Schedule', $result['updated_schedule'] ?? 0],
            ]
        );

        return 0;
    }
}
