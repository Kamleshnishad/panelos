<?php

namespace App\Services;

use App\Models\NotificationSetting;
use App\Models\Order;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

/**
 * Sends WhatsApp / SMS notifications on business events.
 * Each method is a no-op when the channel is not configured or disabled.
 *
 * Usage:
 *   app(NotificationDispatchService::class)->orderConfirmed($order);
 *   app(NotificationDispatchService::class)->dispatchDone($dispatch);
 *   app(NotificationDispatchService::class)->paymentDue($invoice);
 */
class NotificationDispatchService
{
    public function orderConfirmed(Order $order): void
    {
        $ns = NotificationSetting::forCompany($order->company_id);
        if (!$ns->notify_order_confirmed) return;

        $order->loadMissing('customer');
        $customer = $order->customer;
        if (!$customer) return;

        $phone = $customer->whatsapp_no ?: $customer->phone;
        $msg   = "✅ Dear {$customer->name},\n\n"
               . "Your order *{$order->order_no}* has been confirmed with us.\n"
               . "Order Value: ₹" . number_format((float)$order->total_amount, 2) . "\n"
               . "We'll update you when production starts.\n\n"
               . "— " . ($order->company->name ?? 'PanelOS');

        $this->send($ns, $phone, $msg, 'order_confirmed', $order->company_id);
    }

    public function dispatchDone($dispatch): void
    {
        $companyId = $dispatch->company_id ?? $dispatch->batch?->order?->company_id;
        if (!$companyId) return;

        $ns = NotificationSetting::forCompany($companyId);
        if (!$ns->notify_dispatch_done) return;

        $customer = $dispatch->batch?->order?->customer;
        if (!$customer) {
            $dispatch->loadMissing('batch.order.customer');
            $customer = $dispatch->batch?->order?->customer;
        }
        if (!$customer) return;

        $phone       = $customer->whatsapp_no ?: $customer->phone;
        $dispatchNo  = $dispatch->dispatch_no ?? 'N/A';
        $vehicleNo   = $dispatch->vehicle_no ?? '';
        $msg = "🚚 Dear {$customer->name},\n\n"
             . "Your goods have been dispatched! 📦\n"
             . "Dispatch No: *{$dispatchNo}*\n"
             . ($vehicleNo ? "Vehicle: {$vehicleNo}\n" : '')
             . "Please inspect goods on delivery and report any damage within 24 hrs.\n\n"
             . "— " . ($dispatch->batch?->order?->company?->name ?? 'PanelOS');

        $this->send($ns, $phone, $msg, 'dispatch_done', $companyId);
    }

    public function paymentDue(Invoice $invoice, int $daysBefore = 0): void
    {
        $ns = NotificationSetting::forCompany($invoice->company_id);
        if (!$ns->notify_payment_due) return;

        $customer = $invoice->dispatch?->batch?->order?->customer
            ?? $invoice->order?->customer;
        if (!$customer) {
            $invoice->loadMissing('dispatch.batch.order.customer', 'order.customer');
            $customer = $invoice->dispatch?->batch?->order?->customer
                ?? $invoice->order?->customer;
        }
        if (!$customer) return;

        $phone   = $customer->whatsapp_no ?: $customer->phone;
        $dueDate = $invoice->due_date?->format('d M Y') ?? 'N/A';
        $amount  = number_format((float)$invoice->total_amount, 2);
        $label   = $daysBefore > 0 ? "due in {$daysBefore} day(s)" : 'due today';

        $msg = "💰 Dear {$customer->name},\n\n"
             . "This is a reminder that invoice *{$invoice->invoice_no}* (₹{$amount}) is {$label}.\n"
             . "Due Date: {$dueDate}\n\n"
             . "Please arrange payment at your earliest convenience.\n\n"
             . "— " . ($invoice->company?->name ?? 'PanelOS');

        $this->send($ns, $phone, $msg, 'payment_due', $invoice->company_id);
    }

    public function lowStockAlert(string $itemName, float $qty, string $unit, int $companyId): void
    {
        $ns = NotificationSetting::forCompany($companyId);
        if (!$ns->notify_low_stock || !$ns->admin_phone) return;

        $msg = "⚠️ *Low Stock Alert* — PanelOS\n\n"
             . "Item: *{$itemName}*\n"
             . "Current stock: {$qty} {$unit}\n"
             . "Please raise a purchase order.\n";

        $this->send($ns, $ns->admin_phone, $msg, 'low_stock', $companyId);
    }

    /**
     * Core send — tries WhatsApp first (if enabled), falls back to SMS.
     * Silent no-op if neither is ready.
     */
    private function send(NotificationSetting $ns, ?string $phone, string $msg, string $type, int $companyId): void
    {
        if (!$phone) return;

        $sid   = $ns->resolvedSid();
        $token = $ns->resolvedToken();
        if (!$sid || !$token) return;

        try {
            $client = new Client($sid, $token);

            if ($ns->isWhatsappReady()) {
                $from = 'whatsapp:' . $ns->resolvedWhatsappFrom();
                $to   = 'whatsapp:' . $this->e164($phone);
                $client->messages->create($to, ['from' => $from, 'body' => $msg]);
                Log::info("WA notification sent", ['type' => $type, 'to' => $phone, 'company' => $companyId]);
                return;
            }

            if ($ns->isSmsReady()) {
                $client->messages->create($this->e164($phone), ['from' => $ns->resolvedFromNumber(), 'body' => $msg]);
                Log::info("SMS notification sent", ['type' => $type, 'to' => $phone, 'company' => $companyId]);
            }
        } catch (\Throwable $e) {
            Log::error("Notification send failed", ['type' => $type, 'error' => $e->getMessage(), 'company' => $companyId]);
        }
    }

    private function e164(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '+')) return '+' . $digits;
        if (strlen($digits) === 10) return '+91' . $digits;
        return '+' . $digits;
    }
}
