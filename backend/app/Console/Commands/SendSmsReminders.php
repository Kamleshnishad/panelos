<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Console\Command;

class SendSmsReminders extends Command
{
    protected $signature = 'sms:send-reminders {--company_id=} {--days_overdue=7}';

    protected $description = 'Send SMS reminders for overdue invoices, using each company\'s own SMS credentials';

    public function handle()
    {
        $daysOverdue = (int) $this->option('days_overdue');
        $sent = 0;
        $failed = 0;

        $companies = Company::query()
            ->when($this->option('company_id'), fn ($q, $id) => $q->where('id', $id))
            ->pluck('id');

        foreach ($companies as $companyId) {
            // Per-tenant credentials: a global check would skip every tenant
            // whenever the platform default is unset, even when a company has
            // its own Twilio setup.
            $smsService = SmsService::forCompany($companyId);
            if (!$smsService->isEnabled()) {
                continue;
            }

            $overdueInvoices = Invoice::where('company_id', $companyId)
                ->where('status', '!=', 'paid')
                ->where('due_date', '<', now())
                ->with('dispatch.batch.order.customer')
                ->get();

            foreach ($overdueInvoices as $invoice) {
                if (abs(now()->diffInDays($invoice->due_date)) < $daysOverdue) {
                    continue;
                }

                $alreadySent = SmsLog::where('invoice_id', $invoice->id)
                    ->where('type', 'payment_reminder')
                    ->whereDate('created_at', now())
                    ->exists();
                if ($alreadySent) {
                    continue;
                }

                $result = $smsService->sendPaymentReminder($invoice, $companyId);

                if ($result['success']) {
                    $sent++;
                    $this->info("SMS sent for invoice {$invoice->invoice_no}");
                } else {
                    $failed++;
                    $this->error("Failed to send SMS for invoice {$invoice->invoice_no}: {$result['message']}");
                }
            }
        }

        $this->info("\nSMS Reminders Summary:");
        $this->table(['Metric', 'Count'], [['Sent', $sent], ['Failed', $failed]]);

        return 0;
    }
}
