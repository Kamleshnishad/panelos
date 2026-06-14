<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentReminderMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Invoice $invoice;
    public int $daysOverdue;

    public function __construct(Invoice $invoice, int $daysOverdue = 0)
    {
        $this->invoice = $invoice;
        $this->daysOverdue = $daysOverdue;
    }

    public function envelope()
    {
        $subject = $this->daysOverdue > 0
            ? 'Payment Reminder: Invoice ' . $this->invoice->invoice_no . ' is ' . $this->daysOverdue . ' days overdue'
            : 'Payment Due Soon: Invoice ' . $this->invoice->invoice_no;

        return new \Illuminate\Mail\Mailables\Envelope(subject: $subject);
    }

    public function content()
    {
        $remaining = $this->invoice->subtotal + ($this->invoice->taxCalculation->tax_amount ?? 0);
        $paid = \App\Models\PaymentTransaction::where('invoice_id', $this->invoice->id)->sum('amount');
        $remainingDue = max(0, $remaining - $paid);

        return new \Illuminate\Mail\Mailables\Content(
            view: 'emails.payment-reminder',
            with: [
                'invoice' => $this->invoice,
                'customerName' => $this->invoice->dispatch?->batch?->order?->customer?->name ?? 'Customer',
                'invoiceNo' => $this->invoice->invoice_no,
                'remainingDue' => $remainingDue,
                'dueDate' => $this->invoice->due_date->format('F d, Y'),
                'daysOverdue' => $this->daysOverdue,
                'isOverdue' => $this->daysOverdue > 0,
            ],
        );
    }

    public function attachments()
    {
        return [];
    }
}
