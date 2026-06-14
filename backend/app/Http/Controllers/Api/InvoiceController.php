<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InvoiceService;
use App\Services\EmailService;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    protected $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    public function createFromDispatch(Request $request)
    {
        $validated = $request->validate([
            'dispatch_id' => 'required|integer',
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string'
        ]);

        try {
            $invoice = $this->invoiceService->createFromDispatch(
                $validated['dispatch_id'],
                $validated,
                auth()->user()->company_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Invoice created from dispatch',
                'data' => $invoice->load('items', 'taxCalculation')
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function createFromOrder(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string'
        ]);

        try {
            $invoice = $this->invoiceService->createFromOrder(
                $validated['order_id'],
                $validated,
                auth()->user()->company_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Invoice created from order',
                'data' => $invoice->load('items', 'taxCalculation')
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function addItem(Request $request, $invoiceId)
    {
        $validated = $request->validate([
            'panel_type_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'required|numeric|min:0'
        ]);

        try {
            $item = $this->invoiceService->addItem(
                $invoiceId,
                $validated['panel_type_id'],
                $validated['quantity'],
                $validated['unit_price'],
                auth()->user()->company_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Item added to invoice',
                'data' => $item
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function show($invoiceId)
    {
        try {
            $invoice = $this->invoiceService->getInvoiceDetails($invoiceId, auth()->user()->company_id);

            return response()->json([
                'success' => true,
                'data' => $invoice
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    public function list(Request $request)
    {
        $filters = $request->only(['status', 'search', 'from_date', 'to_date']);
        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 20);

        try {
            $invoices = $this->invoiceService->listInvoices(
                $filters,
                auth()->user()->company_id,
                $page,
                $perPage
            );

            return response()->json([
                'success' => true,
                'data' => $invoices->items(),
                'pagination' => [
                    'current_page' => $invoices->currentPage(),
                    'total_pages' => $invoices->lastPage(),
                    'per_page' => $invoices->perPage(),
                    'total' => $invoices->total()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function update(Request $request, $invoiceId)
    {
        $validated = $request->validate([
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string'
        ]);

        try {
            $invoice = $this->invoiceService->updateInvoice(
                $invoiceId,
                $validated,
                auth()->user()->company_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Invoice updated',
                'data' => $invoice
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function send($invoiceId)
    {
        try {
            $invoice = $this->invoiceService->sendInvoice($invoiceId, auth()->user()->company_id);

            return response()->json([
                'success' => true,
                'message' => 'Invoice sent',
                'data' => $invoice
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function accept($invoiceId)
    {
        try {
            $invoice = $this->invoiceService->acceptInvoice($invoiceId, auth()->user()->company_id);

            return response()->json([
                'success' => true,
                'message' => 'Invoice accepted',
                'data' => $invoice
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function markPaid($invoiceId)
    {
        try {
            $invoice = $this->invoiceService->markPaid($invoiceId, auth()->user()->company_id);

            return response()->json([
                'success' => true,
                'message' => 'Invoice marked as paid',
                'data' => $invoice
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function cancel($invoiceId)
    {
        try {
            $invoice = $this->invoiceService->cancelInvoice($invoiceId, auth()->user()->company_id);

            return response()->json([
                'success' => true,
                'message' => 'Invoice cancelled',
                'data' => $invoice
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function duplicate($invoiceId)
    {
        try {
            $invoice = $this->invoiceService->duplicateInvoice($invoiceId, auth()->user()->company_id);

            return response()->json([
                'success' => true,
                'message' => 'Invoice duplicated',
                'data' => $invoice->load('items', 'taxCalculation')
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function downloadPdf($invoiceId)
    {
        try {
            return $this->invoiceService->downloadPdf($invoiceId, auth()->user()->company_id);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function generatePreview($invoiceId)
    {
        try {
            $html = $this->invoiceService->generatePdf($invoiceId, auth()->user()->company_id);

            return response()->json([
                'success' => true,
                'html' => $html
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function sendEmail($invoiceId)
    {
        $emailService = app(EmailService::class);
        $companyId = auth()->user()->company_id;

        try {
            $invoice = $this->invoiceService->getInvoiceDetails($invoiceId, $companyId);

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice not found'
                ], 404);
            }

            $emailService->sendInvoice($invoice, $companyId);

            return response()->json([
                'success' => true,
                'message' => 'Invoice email sent successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function emailPreview($invoiceId, $emailType = 'invoice_sent')
    {
        $emailService = app(EmailService::class);
        $companyId = auth()->user()->company_id;

        try {
            $invoice = $this->invoiceService->getInvoiceDetails($invoiceId, $companyId);

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice not found'
                ], 404);
            }

            $preview = $emailService->getEmailPreview($invoice, $emailType);

            return response()->json([
                'success' => true,
                'data' => $preview
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
