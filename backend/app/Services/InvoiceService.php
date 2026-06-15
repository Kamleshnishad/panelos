<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Dispatch;
use App\Models\Order;
use App\Models\TaxCalculation;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    protected $taxService;

    public function __construct(TaxService $taxService)
    {
        $this->taxService = $taxService;
    }

    public function createFromDispatch($dispatchId, $data = [], $companyId = null)
    {
        return DB::transaction(function () use ($dispatchId, $data, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            $dispatch = Dispatch::where('company_id', $companyId)
                ->with('batch.order.customer', 'items')
                ->findOrFail($dispatchId);

            if ($dispatch->status === 'cancelled') {
                throw new \Exception('Cannot create invoice from cancelled dispatch');
            }

            // Guard against duplicate invoices for the same dispatch.
            if (Invoice::where('company_id', $companyId)
                ->where('dispatch_id', $dispatchId)
                ->where('status', '!=', 'cancelled')
                ->exists()) {
                throw new \Exception('An invoice already exists for this dispatch.');
            }

            $invoice = Invoice::create([
                'company_id' => $companyId,
                'dispatch_id' => $dispatchId,
                'order_id' => $dispatch->batch?->order_id,
                'status' => 'draft',
                'invoice_date' => $data['invoice_date'] ?? now(),
                'due_date' => $data['due_date'] ?? now()->addDays(30),
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null
            ]);

            // Copy items from dispatch
            $subtotal = 0;
            foreach ($dispatch->items as $item) {
                $amount = $item->quantity * $item->unit_price;
                $subtotal += $amount;

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'panel_type_id' => $item->panel_type_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $amount,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'total_with_tax' => $amount
                ]);
            }

            $invoice->update(['subtotal' => $subtotal]);

            // Apply tax
            $this->taxService->applyTaxToInvoice($invoice->id, $companyId);

            return $invoice->refresh();
        });
    }

    public function createFromOrder($orderId, $data = [], $companyId = null)
    {
        return DB::transaction(function () use ($orderId, $data, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            $order = Order::where('company_id', $companyId)
                ->with('items')
                ->findOrFail($orderId);

            // Guard against duplicate invoices for the same order.
            if (Invoice::where('company_id', $companyId)
                ->where('order_id', $orderId)
                ->where('status', '!=', 'cancelled')
                ->exists()) {
                throw new \Exception('An invoice already exists for this order.');
            }

            $invoice = Invoice::create([
                'company_id' => $companyId,
                'order_id' => $orderId,
                'status' => 'draft',
                'invoice_date' => $data['invoice_date'] ?? now(),
                'due_date' => $data['due_date'] ?? now()->addDays(30),
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null
            ]);

            // Copy items from order
            $subtotal = 0;
            foreach ($order->items as $item) {
                $subtotal += $item->amount;

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'panel_type_id' => $item->panel_type_id ?? null,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'total_with_tax' => $item->amount
                ]);
            }

            $invoice->update(['subtotal' => $subtotal]);

            // Apply tax
            $this->taxService->applyTaxToInvoice($invoice->id, $companyId);

            return $invoice->refresh();
        });
    }

    public function addItem($invoiceId, $panelTypeId, $quantity, $unitPrice, $companyId = null)
    {
        return DB::transaction(function () use ($invoiceId, $panelTypeId, $quantity, $unitPrice, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);

            if ($invoice->status !== 'draft') {
                throw new \Exception('Can only add items to draft invoices');
            }

            $amount = $quantity * $unitPrice;

            $item = InvoiceItem::create([
                'invoice_id' => $invoiceId,
                'panel_type_id' => $panelTypeId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'amount' => $amount,
                'total_with_tax' => $amount
            ]);

            $this->calculateTotals($invoiceId, $companyId);

            return $item;
        });
    }

    public function calculateTotals($invoiceId, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);

        $subtotal = $invoice->items->sum('amount');
        $invoice->update(['subtotal' => $subtotal]);

        $this->taxService->applyTaxToInvoice($invoiceId, $companyId);

        return $invoice->refresh();
    }

    public function sendInvoice($invoiceId, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);

        if (!$invoice->canSend()) {
            throw new \Exception('Invoice cannot be sent in ' . $invoice->status . ' status');
        }

        $invoice->update(['status' => 'sent']);

        return $invoice;
    }

    public function acceptInvoice($invoiceId, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);

        if (!$invoice->canAccept()) {
            throw new \Exception('Invoice cannot be accepted in ' . $invoice->status . ' status');
        }

        $invoice->update(['status' => 'accepted']);

        return $invoice;
    }

    public function markPaid($invoiceId, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);

        if (!$invoice->canMarkPaid()) {
            throw new \Exception('Invoice cannot be marked paid in ' . $invoice->status . ' status');
        }

        $invoice->update(['status' => 'paid', 'paid_date' => now()]);

        return $invoice;
    }

    public function cancelInvoice($invoiceId, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);

        if (!$invoice->canCancel()) {
            throw new \Exception('Invoice cannot be cancelled in ' . $invoice->status . ' status');
        }

        $invoice->update(['status' => 'cancelled']);

        return $invoice;
    }

    public function getInvoiceDetails($invoiceId, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        return Invoice::where('company_id', $companyId)
            ->with('dispatch.batch.order.customer', 'items.panelType', 'payments', 'taxCalculation')
            ->findOrFail($invoiceId);
    }

    public function listInvoices($filters = [], $companyId = null, $page = 1, $perPage = 20)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $query = Invoice::where('company_id', $companyId)
            ->with('dispatch.batch.order.customer', 'order.customer', 'payments');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $query->where('invoice_no', 'like', "%{$filters['search']}%");
        }

        if (!empty($filters['from_date'])) {
            $query->whereDate('invoice_date', '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $query->whereDate('invoice_date', '<=', $filters['to_date']);
        }

        return $query->orderBy('invoice_date', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function updateInvoice($invoiceId, $data, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);

        if ($invoice->status !== 'draft') {
            throw new \Exception('Can only update draft invoices');
        }

        $allowedFields = ['due_date', 'notes', 'terms'];
        $updateData = collect($data)->only($allowedFields)->toArray();

        if (empty($updateData)) {
            return $invoice;
        }

        $invoice->update($updateData);

        return $invoice;
    }

    public function duplicateInvoice($invoiceId, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $original = Invoice::where('company_id', $companyId)
            ->with('items')
            ->findOrFail($invoiceId);

        return DB::transaction(function () use ($original, $companyId) {
            $newInvoice = Invoice::create([
                'company_id' => $companyId,
                'dispatch_id' => $original->dispatch_id,
                'order_id' => $original->order_id,
                'status' => 'draft',
                'subtotal' => $original->subtotal,
                'notes' => $original->notes,
                'terms' => $original->terms,
                'invoice_date' => now(),
                'due_date' => now()->addDays(30)
            ]);

            foreach ($original->items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $newInvoice->id,
                    'panel_type_id' => $item->panel_type_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                    'tax_rate' => $item->tax_rate,
                    'tax_amount' => $item->tax_amount,
                    'total_with_tax' => $item->total_with_tax
                ]);
            }

            $this->taxService->applyTaxToInvoice($newInvoice->id, $companyId);

            return $newInvoice->refresh();
        });
    }

    public function generatePdf($invoiceId, $companyId = null, $template = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $invoice = Invoice::where('company_id', $companyId)
            ->with('company', 'items.panelType', 'taxCalculation', 'payments', 'dispatch.batch.order.customer', 'order.customer')
            ->findOrFail($invoiceId);

        $total = $invoice->subtotal + ($invoice->taxCalculation->tax_amount ?? 0);

        // Chosen template (defaults to the current blade); preview can override.
        $view = $template ?: app(\App\Services\DocumentTemplateService::class)->viewFor((int) $companyId, 'invoice', 'invoices.pdf');
        if (!\Illuminate\Support\Facades\View::exists($view)) $view = 'invoices.pdf';

        $html = view($view, compact('invoice', 'total'))->render();

        return $html;
    }

    public function downloadPdf($invoiceId, $companyId = null, $template = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $invoice = Invoice::where('company_id', $companyId)->findOrFail($invoiceId);
        $html = $this->generatePdf($invoiceId, $companyId, $template);

        // Using DomPDF to generate PDF
        $pdf = app('dompdf.wrapper');
        $pdf->loadHTML($html);
        $pdf->setPaper('A4');

        $filename = 'invoice_' . $invoice->invoice_no . '_' . now()->timestamp . '.pdf';

        return $pdf->download($filename);
    }

    /** Convert a numeric amount to Indian-format words (e.g. 423738 → "Four Lakh …"). */
    public static function amountInWords(float $total): string
    {
        $num = (int) round($total);
        if ($num === 0) return 'Zero';

        $ones  = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
                  'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
                  'Seventeen','Eighteen','Nineteen'];
        $tens  = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];

        $two   = function (int $n) use ($ones, $tens): string {
            if ($n < 20) return $ones[$n];
            return trim($tens[(int)($n / 10)] . ' ' . $ones[$n % 10]);
        };
        $three = function (int $n) use ($ones, $two): string {
            $h = (int)($n / 100); $r = $n % 100;
            return trim(($h ? $ones[$h] . ' Hundred ' . ($r ? 'and ' : '') : '') . $two($r));
        };

        $out    = '';
        $crore  = (int)($num / 10000000); $num %= 10000000;
        $lakh   = (int)($num / 100000);   $num %= 100000;
        $thou   = (int)($num / 1000);     $num %= 1000;

        if ($crore) $out .= $three($crore) . ' Crore ';
        if ($lakh)  $out .= $three($lakh)  . ' Lakh ';
        if ($thou)  $out .= $three($thou)  . ' Thousand ';
        if ($num)   $out .= $three($num);

        return trim($out);
    }
}
