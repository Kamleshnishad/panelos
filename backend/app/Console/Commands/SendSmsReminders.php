<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\SmsService;
use App\Models\PaymentReminder;
use Illuminate\Console\Command;

class SendSmsReminders extends Command
{
    protected $signature = 'sms:send-reminders {--company_id=} {--days_overdue=7}';

    protected $description = 'Send SMS reminders for overdue invoices';

    public function handle()
    {
        $smsService = app(SmsService::class);
        $daysOverdue = $this->option('days_overdue');

        if (!$smsService->isEnabled()) {
            $this->warn('SMS service is not enabled');
            return 1;
        }

        $query = Invoice::where('status', '!=', 'paid')
            ->where('due_date', '<', now())
            ->with('dispatch.batch.order.customer');

        if ($this->option('company_id')) {
            $query->where('company_id', $this->option('company_id'));
        }

        $overdueInvoices = $query->get();
        $sent = 0;
        $failed = 0;

        foreach ($overdueInvoices as $invoice) {
            $invoiceDaysOverdue = abs(now()->diffInDays($invoice->due_date));

            if ($invoiceDaysOverdue < $daysOverdue) {
                continue;
            }

            // Check if already sent SMS today
            $todayLog = \App\Models\SmsLog::where('invoice_id', $invoice->id)
                ->where('type', 'payment_reminder')
                ->whereDate('created_at', now())
                ->exists();

            if ($todayLog) {
                continue;
            }

            $result = $smsService->sendPaymentReminder($invoice, $invoice->company_id);

            if ($result['success']) {
                $sent++;
                $this->info("SMS sent for invoice {$invoice->invoice_no}");
            } else {
                $failed++;
                $this->error("Failed to send SMS for invoice {$invoice->invoice_no}: {$result['message']}");
            }
        }

        $this->info("\nSMS Reminders Summary:");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Sent', $sent],
                ['Failed', $failed],
            ]
        );

        return 0;
    }
}
