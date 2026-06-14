<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use App\Services\EmailService;
use App\Services\PaymentGatewayService;
use App\Services\PaymentReminderService;
use App\Models\Invoice;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function recordPayment(Request $request)
    {
        $validated = $request->validate([
            'invoice_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|in:bank_transfer,cash,cheque,upi,other',
            'reference_no' => 'nullable|string'
        ]);

        try {
            $payment = $this->paymentService->recordPayment(
                $validated['invoice_id'],
                $validated['amount'],
                $validated['payment_method'] ?? 'bank_transfer',
                $validated['reference_no'] ?? null,
                auth()->user()->company_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment recorded',
                'data' => $payment
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getPaymentHistory($invoiceId)
    {
        try {
            $history = $this->paymentService->getPaymentHistory($invoiceId, auth()->user()->company_id);

            return response()->json([
                'success' => true,
                'data' => $history
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    public function getPaymentStatus($invoiceId)
    {
        try {
            $status = $this->paymentService->getPaymentStatus($invoiceId, auth()->user()->company_id);

            return response()->json([
                'success' => true,
                'data' => $status
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    public function issueReminder($invoiceId)
    {
        try {
            $reminder = $this->paymentService->issueReminder($invoiceId, auth()->user()->company_id);

            return response()->json([
                'success' => true,
                'message' => 'Payment reminder issued',
                'data' => $reminder
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function writeOff(Request $request, $invoiceId)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string'
        ]);

        try {
            $result = $this->paymentService->writeOff(
                $invoiceId,
                $validated['amount'],
                $validated['reason'],
                auth()->user()->company_id
            );

            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function reconcile(Request $request)
    {
        $validated = $request->validate([
            'invoice_id' => 'required|integer',
            'paid_amount' => 'required|numeric|min:0.01',
            'reference_no' => 'nullable|string'
        ]);

        try {
            $status = $this->paymentService->reconcilePayment(
                $validated['invoice_id'],
                $validated['paid_amount'],
                $validated['reference_no'] ?? null,
                auth()->user()->company_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment reconciled',
                'data' => $status
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getUnpaidInvoices(Request $request)
    {
        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 20);

        try {
            $invoices = $this->paymentService->getUnpaidInvoices(auth()->user()->company_id, $page, $perPage);

            return response()->json([
                'success' => true,
                'data' => $invoices
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function sendPaymentReminder($invoiceId)
    {
        $emailService = app(EmailService::class);
        $companyId = auth()->user()->company_id;

        try {
            $invoice = \App\Models\Invoice::where('company_id', $companyId)->findOrFail($invoiceId);

            $emailService->sendPaymentReminder($invoice, $companyId);

            return response()->json([
                'success' => true,
                'message' => 'Payment reminder email sent successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function sendPaymentConfirmation($invoiceId)
    {
        $emailService = app(EmailService::class);
        $companyId = auth()->user()->company_id;

        try {
            $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);
            $payment = \App\Models\PaymentTransaction::where('invoice_id', $invoiceId)
                ->orderBy('transaction_date', 'desc')
                ->first();

            if (!$payment) {
                throw new \Exception('No payment found for this invoice');
            }

            $emailService->sendPaymentConfirmation($invoice, $payment, $companyId);

            return response()->json([
                'success' => true,
                'message' => 'Payment confirmation email sent successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function createCheckoutSession($invoiceId)
    {
        $companyId = auth()->user()->company_id;

        try {
            $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);
            $gatewayService = new PaymentGatewayService();
            $result = $gatewayService->createCheckoutSession($invoice, $companyId);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'session_id' => $result['session_id'],
                    'payment_url' => $result['payment_url'],
                    'expires_at' => $result['expires_at']
                ]);
            }

            return response()->json($result, 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function createPaymentIntent($invoiceId)
    {
        $companyId = auth()->user()->company_id;

        try {
            $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);
            $gatewayService = new PaymentGatewayService();
            $result = $gatewayService->createPaymentIntent($invoice, $companyId);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'client_secret' => $result['client_secret'],
                    'intent_id' => $result['intent_id'],
                    'amount' => $result['amount']
                ]);
            }

            return response()->json($result, 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function confirmPaymentIntent(Request $request)
    {
        $validated = $request->validate([
            'intent_id' => 'required|string',
            'payment_method_id' => 'nullable|string'
        ]);

        try {
            $gatewayService = new PaymentGatewayService();
            $result = $gatewayService->confirmPaymentIntent($validated['intent_id'], $validated['payment_method_id'] ?? null);

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getPaymentLink($invoiceId)
    {
        $companyId = auth()->user()->company_id;

        try {
            $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);
            $gatewayService = new PaymentGatewayService();
            $result = $gatewayService->createPaymentLink($invoice, $companyId);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'payment_link' => $result['payment_link'],
                    'link_id' => $result['link_id'],
                    'expires_at' => $result['expires_at']
                ]);
            }

            return response()->json($result, 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function handleStripeWebhook(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('stripe-signature');

        $gatewayService = new PaymentGatewayService();

        if (!$gatewayService->verifyWebhookSignature($payload, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        try {
            $event = json_decode($payload, true);

            if ($event['type'] === 'payment_intent.succeeded') {
                $result = $gatewayService->handlePaymentSucceeded((object)$event);
                return response()->json(['success' => true]);
            }

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function scheduleReminder($invoiceId)
    {
        $companyId = auth()->user()->company_id;

        try {
            $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);
            $reminderService = new PaymentReminderService(app(EmailService::class));
            $result = $reminderService->scheduleRemindersForInvoice($invoice, $companyId);

            return response()->json([
                'success' => $result['scheduled'],
                'message' => $result['message'] ?? 'Reminder scheduled',
                'data' => $result
            ], $result['scheduled'] ? 201 : 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getReminderStatus($invoiceId)
    {
        $companyId = auth()->user()->company_id;

        try {
            $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);
            $reminderService = new PaymentReminderService(app(EmailService::class));
            $status = $reminderService->getReminderStatus($invoiceId);

            return response()->json([
                'success' => true,
                'data' => $status
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    public function sendManualReminder($invoiceId)
    {
        $companyId = auth()->user()->company_id;

        try {
            $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);
            $emailService = app(EmailService::class);
            $emailService->sendPaymentReminder($invoice, $companyId);

            return response()->json([
                'success' => true,
                'message' => 'Payment reminder sent successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getReminderStats()
    {
        $companyId = auth()->user()->company_id;

        try {
            $reminderService = new PaymentReminderService(app(EmailService::class));
            $stats = $reminderService->getCompanyReminderStats($companyId);

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
