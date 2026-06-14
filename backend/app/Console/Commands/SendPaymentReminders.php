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
            $result = $reminderService->sendDueReminders($this->option('company_id'));
        } else {
            $this->info('Sending reminders for all companies...');

            $companies = Company::pluck('id');
            $totalResults = [
                'total' => 0,
                'sent' => 0,
                'failed' => 0,
                'updated_schedule' => 0,
            ];

            foreach ($companies as $companyId) {
                $result = $reminderService->sendDueReminders($companyId);
                $totalResults['total'] += $result['total'] ?? 0;
                $totalResults['sent'] += $result['sent'] ?? 0;
                $totalResults['failed'] += $result['failed'] ?? 0;
                $totalResults['updated_schedule'] += $result['updated_schedule'] ?? 0;
            }

            $result = $totalResults;
        }

        $this->info('Payment Reminders Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Pending', $result['total'] ?? 0],
                ['Sent', $result['sent'] ?? 0],
                ['Failed', $result['failed'] ?? 0],
                ['Updated Schedule', $result['updated_schedule'] ?? 0],
            ]
        );

        return 0;
    }
}
