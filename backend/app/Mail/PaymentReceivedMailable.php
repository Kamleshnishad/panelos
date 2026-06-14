<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\PaymentTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentReceivedMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Invoice $invoice;
    public PaymentTransaction $payment;

    public function __construct(Invoice $invoice, PaymentTransaction $payment)
    {
        $this->invoice = $invoice;
        $this->payment = $payment;
    }

    public function envelope()
    {
        return new \Illuminate\Mail\Mailables\Envelope(
            subject: 'Payment Received - Invoice ' . $this->invoice->invoice_no,
        );
    }

    public function content()
    {
        $total = $this->invoice->subtotal + ($this->invoice->taxCalculation->tax_amount ?? 0);
        $paid = \App\Models\PaymentTransaction::where('invoice_id', $this->invoice->id)->sum('amount');
        $remaining = max(0, $total - $paid);
        $paymentStatus = $remaining <= 0 ? 'Fully Paid' : 'Partially Paid';

        return new \Illuminate\Mail\Mailables\Content(
            view: 'emails.payment-received',
            with: [
                'invoice' => $this->invoice,
                'payment' => $this->payment,
                'customerName' => $this->invoice->dispatch?->batch?->order?->customer?->name ?? 'Customer',
                'invoiceNo' => $this->invoice->invoice_no,
                'paymentAmount' => $this->payment->amount,
                'paymentMethod' => $this->payment->payment_method,
                'totalAmount' => $total,
                'remainingDue' => $remaining,
                'paymentStatus' => $paymentStatus,
                'paymentDate' => $this->payment->transaction_date->format('F d, Y'),
            ],
        );
    }

    public function attachments()
    {
        return [];
    }
}
