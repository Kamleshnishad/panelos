<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Mail\InvoiceSentMailable;
use App\Mail\PaymentReminderMailable;
use App\Mail\PaymentReceivedMailable;
use Illuminate\Support\Facades\Mail;

class EmailService
{
    protected $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    public function sendInvoice(Invoice $invoice, $companyId = null)
    {
        try {
            $customerEmail = $this->getCustomerEmail($invoice);

            if (!$customerEmail) {
                throw new \Exception('Customer email not found');
            }

            // Generate PDF
            $pdfHtml = $this->invoiceService->generatePdf($invoice->id, $companyId);

            // For now, send without attachment (PDF generation requires DomPDF)
            // In production, you would generate and attach the PDF file
            Mail::to($customerEmail)
                ->send(new InvoiceSentMailable($invoice));

            // Log email sent
            $this->logEmailSent($invoice, 'invoice_sent', $customerEmail);

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to send invoice email: ' . $e->getMessage());
            throw $e;
        }
    }

    public function sendPaymentReminder(Invoice $invoice, $companyId = null)
    {
        try {
            $customerEmail = $this->getCustomerEmail($invoice);

            if (!$customerEmail) {
                throw new \Exception('Customer email not found');
            }

            $daysOverdue = now()->diffInDays($invoice->due_date);

            Mail::to($customerEmail)
                ->send(new PaymentReminderMailable($invoice, $daysOverdue));

            // Log email sent
            $this->logEmailSent($invoice, 'payment_reminder', $customerEmail);

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to send payment reminder email: ' . $e->getMessage());
            throw $e;
        }
    }

    public function sendPaymentConfirmation(Invoice $invoice, PaymentTransaction $payment, $companyId = null)
    {
        try {
            $customerEmail = $this->getCustomerEmail($invoice);

            if (!$customerEmail) {
                throw new \Exception('Customer email not found');
            }

            Mail::to($customerEmail)
                ->send(new PaymentReceivedMailable($invoice, $payment));

            // Log email sent
            $this->logEmailSent($invoice, 'payment_received', $customerEmail);

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to send payment confirmation email: ' . $e->getMessage());
            throw $e;
        }
    }

    public function sendBatchReminders($companyId, $daysOverdue = 1)
    {
        $invoices = Invoice::where('company_id', $companyId)
            ->where('status', '!=', 'paid')
            ->where('status', '!=', 'cancelled')
            ->where('due_date', '<', now()->subDays($daysOverdue))
            ->get();

        $sent = 0;
        foreach ($invoices as $invoice) {
            try {
                $this->sendPaymentReminder($invoice, $companyId);
                $sent++;
            } catch (\Exception $e) {
                \Log::error('Batch reminder failed for invoice ' . $invoice->id . ': ' . $e->getMessage());
            }
        }

        return [
            'total_invoices' => $invoices->count(),
            'emails_sent' => $sent,
            'failed' => $invoices->count() - $sent
        ];
    }

    protected function getCustomerEmail(Invoice $invoice)
    {
        $customer = $invoice->dispatch?->batch?->order?->customer;

        if ($customer && $customer->email) {
            return $customer->email;
        }

        return null;
    }

    protected function logEmailSent(Invoice $invoice, $emailType, $recipientEmail)
    {
        // Store email log in database or file system
        // For now, we'll just log to Laravel logs
        \Log::info('Email sent', [
            'invoice_id' => $invoice->id,
            'type' => $emailType,
            'recipient' => $recipientEmail,
            'timestamp' => now()
        ]);
    }

    public function getEmailPreview(Invoice $invoice, $emailType = 'invoice_sent')
    {
        $customerName = $invoice->dispatch?->batch?->order?->customer?->name ?? 'Customer';
        $total = $invoice->subtotal + ($invoice->taxCalculation->tax_amount ?? 0);

        switch ($emailType) {
            case 'invoice_sent':
                return [
                    'subject' => 'Invoice ' . $invoice->invoice_no . ' - Payment Due ' . $invoice->due_date->format('M d, Y'),
                    'preview' => 'Your invoice ' . $invoice->invoice_no . ' for $' . number_format($total, 2) . ' is ready.',
                ];
            case 'payment_reminder':
                $daysOverdue = now()->diffInDays($invoice->due_date);
                return [
                    'subject' => $daysOverdue > 0
                        ? 'Payment Reminder: Invoice ' . $invoice->invoice_no . ' is ' . $daysOverdue . ' days overdue'
                        : 'Payment Due Soon: Invoice ' . $invoice->invoice_no,
                    'preview' => 'Please remit payment of $' . number_format($total, 2) . ' by ' . $invoice->due_date->format('F d, Y'),
                ];
            case 'payment_received':
                return [
                    'subject' => 'Payment Received - Invoice ' . $invoice->invoice_no,
                    'preview' => 'We have received your payment. Thank you!',
                ];
            default:
                return null;
        }
    }
}
