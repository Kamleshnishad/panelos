<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoiceSentMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Invoice $invoice;
    public string $pdfPath;

    public function __construct(Invoice $invoice, string $pdfPath = null)
    {
        $this->invoice = $invoice;
        $this->pdfPath = $pdfPath;
    }

    public function envelope()
    {
        return new \Illuminate\Mail\Mailables\Envelope(
            subject: 'Invoice ' . $this->invoice->invoice_no . ' - Payment Due ' . $this->invoice->due_date->format('M d, Y'),
        );
    }

    public function content()
    {
        return new \Illuminate\Mail\Mailables\Content(
            view: 'emails.invoice-sent',
            with: [
                'invoice' => $this->invoice,
                'customerName' => $this->invoice->dispatch?->batch?->order?->customer?->name ?? 'Customer',
                'invoiceNo' => $this->invoice->invoice_no,
                'total' => $this->invoice->subtotal + ($this->invoice->taxCalculation->tax_amount ?? 0),
                'dueDate' => $this->invoice->due_date->format('F d, Y'),
            ],
        );
    }

    public function attachments()
    {
        if ($this->pdfPath && file_exists($this->pdfPath)) {
            return [
                \Illuminate\Mail\Mailables\Attachment::fromPath($this->pdfPath)
                    ->as('invoice_' . $this->invoice->invoice_no . '.pdf')
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
