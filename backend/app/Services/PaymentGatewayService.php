<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\PaymentLink;

class PaymentGatewayService
{
    protected $stripeKey;
    protected $webhookSecret;

    public function __construct()
    {
        $this->stripeKey = config('services.stripe.secret');
        $this->webhookSecret = config('services.stripe.webhook_secret');
        Stripe::setApiKey($this->stripeKey);
    }

    public function createCheckoutSession(Invoice $invoice, $companyId)
    {
        try {
            $session = Session::create([
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'success_url' => $this->getSuccessUrl($invoice->id),
                'cancel_url' => $this->getCancelUrl($invoice->id),
                'customer_email' => $this->getCustomerEmail($invoice),
                'client_reference_id' => "invoice_{$invoice->id}_company_{$companyId}",
                'line_items' => [
                    [
                        'price_data' => [
                            'currency' => 'inr',
                            'unit_amount' => $this->convertToStripeAmount($invoice->getTotal()),
                            'product_data' => [
                                'name' => "Invoice #{$invoice->invoice_no}",
                                'description' => $this->getLineItemDescription($invoice),
                            ],
                        ],
                        'quantity' => 1,
                    ]
                ],
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'company_id' => $companyId,
                    'invoice_number' => $invoice->invoice_no,
                ]
            ]);

            return [
                'success' => true,
                'session_id' => $session->id,
                'payment_url' => $session->url,
                'expires_at' => date('Y-m-d H:i:s', $session->expires_at)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to create checkout session: ' . $e->getMessage()
            ];
        }
    }

    public function createPaymentIntent(Invoice $invoice, $companyId)
    {
        try {
            $intent = PaymentIntent::create([
                'amount' => $this->convertToStripeAmount($invoice->getTotal()),
                'currency' => 'inr',
                'description' => "Payment for Invoice #{$invoice->invoice_no}",
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'company_id' => $companyId,
                ],
                'receipt_email' => $this->getCustomerEmail($invoice),
            ]);

            return [
                'success' => true,
                'client_secret' => $intent->client_secret,
                'intent_id' => $intent->id,
                'amount' => $invoice->getTotal(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to create payment intent: ' . $e->getMessage()
            ];
        }
    }

    public function confirmPaymentIntent($intentId, $paymentMethodId)
    {
        try {
            $intent = PaymentIntent::retrieve($intentId);

            if ($intent->status === 'succeeded') {
                return [
                    'success' => true,
                    'status' => 'paid',
                    'transaction_id' => $intent->charges->data[0]->id ?? null
                ];
            }

            return [
                'success' => false,
                'status' => $intent->status,
                'message' => 'Payment intent not yet confirmed'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to confirm payment: ' . $e->getMessage()
            ];
        }
    }

    public function createPaymentLink(Invoice $invoice, $companyId)
    {
        try {
            $link = PaymentLink::create([
                'line_items' => [
                    [
                        'price_data' => [
                            'currency' => 'inr',
                            'unit_amount' => $this->convertToStripeAmount($invoice->getTotal()),
                            'product_data' => [
                                'name' => "Invoice #{$invoice->invoice_no}",
                                'description' => $this->getLineItemDescription($invoice),
                            ],
                        ],
                        'quantity' => 1,
                    ]
                ],
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'company_id' => $companyId,
                ],
            ]);

            return [
                'success' => true,
                'payment_link' => $link->url,
                'link_id' => $link->id,
                'expires_at' => $link->expires_at ? date('Y-m-d H:i:s', $link->expires_at) : null
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to create payment link: ' . $e->getMessage()
            ];
        }
    }

    public function verifyWebhookSignature($payload, $signature)
    {
        try {
            \Stripe\Webhook::constructEvent($payload, $signature, $this->webhookSecret);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function handlePaymentSucceeded($event)
    {
        $paymentIntent = $event->data->object;

        if (!isset($paymentIntent->metadata->invoice_id)) {
            return ['success' => false, 'message' => 'No invoice ID in metadata'];
        }

        $invoiceId    = (int) $paymentIntent->metadata->invoice_id;
        $amountPaid   = $paymentIntent->amount / 100; // Stripe uses minor units
        $gatewayTxnId = $paymentIntent->charges->data[0]->id ?? $paymentIntent->id;

        try {
            return DB::transaction(function () use ($invoiceId, $amountPaid, $gatewayTxnId, $paymentIntent) {
                // Defence-in-depth: trust the invoice's company_id, not metadata.
                $invoice = Invoice::withoutGlobalScope('tenant')->lockForUpdate()->find($invoiceId);
                if (!$invoice) {
                    return ['success' => false, 'message' => 'Invoice not found'];
                }

                // Idempotency: Stripe retries webhooks on 5xx; same transaction_id
                // means we already recorded this payment. Return success without
                // double-crediting.
                $existing = PaymentTransaction::withoutGlobalScope('tenant')
                    ->where('company_id', $invoice->company_id)
                    ->where('transaction_id', $gatewayTxnId)
                    ->exists();
                if ($existing) {
                    return ['success' => true, 'idempotent' => true];
                }

                PaymentTransaction::create([
                    'company_id'       => $invoice->company_id,
                    'invoice_id'       => $invoice->id,
                    'amount'           => $amountPaid,
                    'payment_method'   => 'stripe',
                    'status'           => 'completed',
                    'transaction_id'   => $gatewayTxnId,
                    'transaction_date' => date('Y-m-d H:i:s', $paymentIntent->created),
                ]);

                // If the new total of payments covers the invoice, transition status.
                $invoice->refresh()->load('payments', 'taxCalculation');
                if ($invoice->getTotal() > 0 && $invoice->remaining_due <= 0.01 && $invoice->canMarkPaid()) {
                    $invoice->update(['status' => 'paid', 'paid_date' => now()]);
                }

                return ['success' => true];
            });
        } catch (\Exception $e) {
            Log::error('Stripe webhook handlePaymentSucceeded failed', [
                'invoice_id' => $invoiceId,
                'gateway_txn' => $gatewayTxnId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getPaymentStatus($paymentIntentId)
    {
        try {
            $intent = PaymentIntent::retrieve($paymentIntentId);

            return [
                'success' => true,
                'status' => $intent->status,
                'amount' => $intent->amount / 100,
                'currency' => $intent->currency,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to retrieve payment status: ' . $e->getMessage()
            ];
        }
    }

    protected function convertToStripeAmount($amount)
    {
        return (int)($amount * 100);
    }

    protected function getCustomerEmail(Invoice $invoice)
    {
        return $invoice->dispatch?->batch?->order?->customer?->email ?? 'customer@example.com';
    }

    protected function getLineItemDescription(Invoice $invoice)
    {
        $itemCount = $invoice->items->count();
        return "Invoice with {$itemCount} item(s) - Due: " . $invoice->due_date->format('M d, Y');
    }

    protected function getSuccessUrl($invoiceId)
    {
        return config('app.frontend_url') . "/invoices/{$invoiceId}?payment=success";
    }

    protected function getCancelUrl($invoiceId)
    {
        return config('app.frontend_url') . "/invoices/{$invoiceId}?payment=cancelled";
    }
}
