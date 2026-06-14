<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentReminder;
use App\Mail\PaymentReminderMailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class PaymentReminderService
{
    protected $emailService;

    // Days after due date to send reminders
    protected $reminderSchedule = [
        'first' => 3,    // 3 days after due date
        'second' => 7,   // 7 days after due date
        'final' => 14,   // 14 days after due date
    ];

    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    public function scheduleRemindersForInvoice(Invoice $invoice, $companyId)
    {
        try {
            $daysOverdue = max(0, now()->diffInDays($invoice->due_date, false));

            if ($daysOverdue < $this->reminderSchedule['first']) {
                return ['scheduled' => false, 'message' => 'Invoice not yet due'];
            }

            $existingReminder = PaymentReminder::where('invoice_id', $invoice->id)->first();

            if ($existingReminder) {
                return ['scheduled' => false, 'message' => 'Reminder already exists'];
            }

            $firstReminderDate = $invoice->due_date->copy()->addDays($this->reminderSchedule['first']);

            $reminder = PaymentReminder::create([
                'company_id' => $companyId,
                'invoice_id' => $invoice->id,
                'reminder_type' => 'first',
                'reminder_count' => 0,
                'next_reminder_at' => $firstReminderDate,
                'is_paid' => false,
            ]);

            return [
                'scheduled' => true,
                'reminder_id' => $reminder->id,
                'next_reminder_at' => $firstReminderDate
            ];
        } catch (\Exception $e) {
            Log::error('Failed to schedule reminder', ['error' => $e->getMessage()]);
            return ['scheduled' => false, 'error' => $e->getMessage()];
        }
    }

    public function sendDueReminders($companyId = null)
    {
        try {
            $query = PaymentReminder::pending();

            if ($companyId) {
                $query->byCompany($companyId);
            }

            $reminders = $query->get();
            $results = [
                'total' => $reminders->count(),
                'sent' => 0,
                'failed' => 0,
                'updated_schedule' => 0,
            ];

            foreach ($reminders as $reminder) {
                $invoice = $reminder->invoice;

                // Check if invoice is paid
                if ($invoice->isPaid()) {
                    $reminder->update(['is_paid' => true]);
                    $results['updated_schedule']++;
                    continue;
                }

                try {
                    $daysOverdue = now()->diffInDays($invoice->due_date, false);

                    $this->emailService->sendPaymentReminder($invoice, $invoice->company_id);

                    $reminder->update([
                        'reminder_count' => $reminder->reminder_count + 1,
                        'last_reminded_at' => now(),
                        'next_reminder_at' => $this->calculateNextReminder($reminder, $daysOverdue),
                        'reminder_type' => $this->determineReminderType($daysOverdue)
                    ]);

                    $results['sent']++;

                    Log::info('Payment reminder sent', [
                        'invoice_id' => $invoice->id,
                        'company_id' => $invoice->company_id,
                        'days_overdue' => $daysOverdue
                    ]);
                } catch (\Exception $e) {
                    $results['failed']++;
                    Log::error('Failed to send payment reminder', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return $results;
        } catch (\Exception $e) {
            Log::error('Payment reminder job failed', ['error' => $e->getMessage()]);
            return [
                'error' => $e->getMessage(),
                'total' => 0,
                'sent' => 0,
                'failed' => 0,
            ];
        }
    }

    public function markReminderAsPaid($invoiceId)
    {
        try {
            $reminder = PaymentReminder::where('invoice_id', $invoiceId)->first();

            if ($reminder) {
                $reminder->update(['is_paid' => true]);
                Log::info('Payment reminder marked as paid', ['invoice_id' => $invoiceId]);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Failed to mark reminder as paid', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getReminderStatus($invoiceId)
    {
        $reminder = PaymentReminder::where('invoice_id', $invoiceId)->first();

        if (!$reminder) {
            return null;
        }

        $invoice = $reminder->invoice;
        $daysOverdue = now()->diffInDays($invoice->due_date, false);

        return [
            'reminder_id' => $reminder->id,
            'reminder_type' => $reminder->reminder_type,
            'reminder_count' => $reminder->reminder_count,
            'last_reminded_at' => $reminder->last_reminded_at,
            'next_reminder_at' => $reminder->next_reminder_at,
            'days_overdue' => $daysOverdue,
            'is_paid' => $reminder->is_paid,
        ];
    }

    protected function calculateNextReminder(PaymentReminder $reminder, $daysOverdue)
    {
        $schedule = [
            'first' => 3,
            'second' => 7,
            'final' => 14,
        ];

        $nextReminderDays = match ($reminder->reminder_type) {
            'first' => $schedule['second'],
            'second' => $schedule['final'],
            'final' => $schedule['final'] + 7, // Weekly after final
            default => $schedule['first'],
        };

        return now()->addDays($nextReminderDays - $daysOverdue);
    }

    protected function determineReminderType($daysOverdue)
    {
        return match (true) {
            $daysOverdue < 7 => 'first',
            $daysOverdue < 14 => 'second',
            default => 'final',
        };
    }

    public function getCompanyReminderStats($companyId)
    {
        $totalReminders = PaymentReminder::byCompany($companyId)->count();
        $pendingReminders = PaymentReminder::byCompany($companyId)->pending()->count();
        $paidReminders = PaymentReminder::byCompany($companyId)->where('is_paid', true)->count();

        $upcomingReminders = PaymentReminder::byCompany($companyId)
            ->where('is_paid', false)
            ->where('next_reminder_at', '>', now())
            ->where('next_reminder_at', '<=', now()->addDays(7))
            ->count();

        return [
            'total_reminders' => $totalReminders,
            'pending_reminders' => $pendingReminders,
            'paid_reminders' => $paidReminders,
            'upcoming_in_7_days' => $upcomingReminders,
        ];
    }
}
