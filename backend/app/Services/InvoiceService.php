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

    private function persistTotals(Invoice $invoice): Invoice
    {
        $invoice->refresh()->loadMissing('taxCalculation');
        $taxAmount = (float) ($invoice->taxCalculation->tax_amount ?? 0);
        $invoice->update([
            'tax_amount'   => $taxAmount,
            'total_amount' => (float) $invoice->subtotal + $taxAmount,
        ]);
        return $invoice->refresh();
    }

    public function createFromDispatch($dispatchId, $data = [], $companyId = null)
    {
        return \App\Support\DocNumber::retry(fn () => DB::transaction(function () use ($dispatchId, $data, $companyId) {
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

            // Copy items from dispatch. The stored `amount` is authoritative —
            // recomputing quantity × unit_price re-introduces the rounding the
            // weighted-average rate already lost (78 m² at an averaged
            // ₹1461.54 bills ₹114,000.12 instead of ₹114,000.00).
            $subtotal = 0;
            foreach ($dispatch->items as $item) {
                $amount = (float) ($item->amount ?: $item->quantity * $item->unit_price);
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

            $subtotal += $this->addNonPanelLines($invoice, $dispatch->batch?->order);
            $subtotal += $this->addTransportLine($invoice, $dispatch, $dispatch->batch?->order);

            $invoice->update(['subtotal' => $subtotal]);

            // Apply tax then persist total_amount/tax_amount so accessors and PDFs don't drift
            $this->taxService->applyTaxToInvoice($invoice->id, $companyId);
            return $this->persistTotals($invoice);
        }));
    }

    public function createFromOrder($orderId, $data = [], $companyId = null)
    {
        return \App\Support\DocNumber::retry(fn () => DB::transaction(function () use ($orderId, $data, $companyId) {
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

            $subtotal += $this->addNonPanelLines($invoice, $order);
            $subtotal += $this->addTransportLine($invoice, null, $order);

            $invoice->update(['subtotal' => $subtotal]);

            // Apply tax then persist total_amount/tax_amount so accessors and PDFs don't drift
            $this->taxService->applyTaxToInvoice($invoice->id, $companyId);
            return $this->persistTotals($invoice);
        }));
    }

    /**
     * Bill the order's non-panel charges: accessories, doors/windows and
     * installation. Dispatch items only ever carried panel rows, so these lines
     * had no path to an invoice at all and the revenue was simply lost (the
     * system's own /reports/reconciliation flagged the orders as under_invoiced).
     *
     * They are read from the order's accepted quotation — accepted quotations
     * are immutable (QuotationService::update only allows boq/draft/sent), so
     * that is a stable source and needs no second snapshot table.
     *
     * Billed once per order: if another live invoice for the same order already
     * carries them, this is a no-op, so a multi-dispatch order can't double-bill.
     *
     * NOTE: fixed transport (orders.transport_amount) is deliberately NOT billed
     * here. The quotation adds it after tax and leaves it untaxed (§21.2), which
     * a per-line invoice cannot express today — billing it would change its tax
     * treatment. It stays a known gap pending a business decision.
     *
     * @return float total added to the invoice subtotal
     */
    private function addNonPanelLines(Invoice $invoice, ?Order $order): float
    {
        if (!$order) {
            return 0.0;
        }

        $alreadyBilled = InvoiceItem::whereIn('invoice_id',
                Invoice::where('order_id', $order->id)
                    ->where('id', '!=', $invoice->id)
                    ->where('status', '!=', 'cancelled')
                    ->pluck('id'))
            ->whereNull('panel_type_id')
            ->exists();
        if ($alreadyBilled) {
            return 0.0;
        }

        $quotation = $order->quotation;
        if (!$quotation) {
            return 0.0;
        }

        $added = 0.0;

        foreach ($quotation->accessories as $accessory) {
            $amount = (float) ($accessory->pivot->amount ?? 0);
            if ($amount <= 0) continue;

            $qty  = (float) ($accessory->pivot->quantity ?? 1);
            $rate = (float) ($accessory->pivot->unit_price ?? 0);
            $label = $accessory->pivot->description ?: $accessory->name;
            if (($accessory->pivot->door_type ?? null)) {
                $label .= " ({$accessory->pivot->door_type} {$accessory->pivot->door_width}×{$accessory->pivot->door_height}mm)";
            }

            InvoiceItem::create([
                'invoice_id'     => $invoice->id,
                'panel_type_id'  => null,
                'description'    => $label,
                'quantity'       => $qty,
                'unit_price'     => $rate,
                'amount'         => $amount,
                'tax_rate'       => 0,
                'tax_amount'     => 0,
                'total_with_tax' => $amount,
            ]);
            $added += $amount;
        }

        // Installation, custom items and typed-in doors are kept as rows on the quotation; bill each as its own
        // line. installation_amount is their sum, so the invoice total is the same as the old single lump.
        if ($quotation->lines->isNotEmpty()) {
            foreach ($quotation->lines as $line) {
                $amount = (float) $line->amount;
                if ($amount <= 0) continue;

                $label = $line->description ?: match ($line->type) {
                    'door' => 'Door / window', 'custom' => 'Custom item', default => 'Installation / site work',
                };
                if ($line->type === 'door' && $line->door_width && $line->door_height) {
                    $label .= " ({$line->door_width}×{$line->door_height}mm)";
                }

                InvoiceItem::create([
                    'invoice_id'     => $invoice->id,
                    'panel_type_id'  => null,
                    'description'    => $label,
                    'quantity'       => (float) $line->quantity,
                    'unit_price'     => (float) $line->unit_price,
                    'amount'         => $amount,
                    'tax_rate'       => 0,
                    'tax_amount'     => 0,
                    'total_with_tax' => $amount,
                ]);
                $added += $amount;
            }
            return $added;
        }

        $installation = (float) ($quotation->installation_amount ?? 0);
        if ($installation > 0) {
            InvoiceItem::create([
                'invoice_id'     => $invoice->id,
                'panel_type_id'  => null,
                'description'    => 'Installation / site work',
                'quantity'       => 1,
                'unit_price'     => $installation,
                'amount'         => $installation,
                'tax_rate'       => 0,
                'tax_amount'     => 0,
                'total_with_tax' => $installation,
            ]);
            $added += $installation;
        }

        return $added;
    }

    /**
     * Bill freight (PB-021).
     *
     * Transport is settled at dispatch, not at quotation: the real cost depends
     * on which vehicle is available that day and is renegotiated several times
     * before the goods move. So the dispatch's actual amount wins; the order's
     * quoted figure is only a fallback for the case where it was agreed up front
     * and no actual was entered. Either way it now gets billed — previously
     * neither did, and the money was simply lost.
     *
     * It goes into the subtotal, so it carries the same GST rate as the goods.
     * That matches the usual treatment of freight as part of a composite supply,
     * but it is a tax question — worth confirming with the client's accountant.
     */
    private function addTransportLine(Invoice $invoice, ?Dispatch $dispatch, ?Order $order): float
    {
        $amount = (float) ($dispatch?->transport_amount ?: 0);
        $source = 'actual';

        if ($amount <= 0) {
            $amount = (float) ($order?->transport_fixed ? $order->transport_amount : 0);
            $source = 'quoted';
        }

        if ($amount <= 0) {
            return 0.0;
        }

        // Never bill freight twice across a multi-dispatch order.
        $already = InvoiceItem::whereIn('invoice_id',
                Invoice::where('order_id', $order?->id)
                    ->where('id', '!=', $invoice->id)
                    ->where('status', '!=', 'cancelled')
                    ->pluck('id'))
            ->where('description', 'like', 'Transport%')
            ->exists();
        if ($already && $source === 'quoted') {
            return 0.0;
        }

        $label = 'Transport / freight';
        if ($dispatch?->transporter_name) {
            $label .= ' — ' . $dispatch->transporter_name
                . ($dispatch->vehicle_no ? ' (' . $dispatch->vehicle_no . ')' : '');
        }

        InvoiceItem::create([
            'invoice_id'     => $invoice->id,
            'panel_type_id'  => null,
            'description'    => $label,
            'quantity'       => 1,
            'unit_price'     => $amount,
            'amount'         => $amount,
            'tax_rate'       => 0,
            'tax_amount'     => 0,
            'total_with_tax' => $amount,
        ]);

        return $amount;
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

        return $this->persistTotals($invoice);
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

        return DB::transaction(function () use ($invoiceId, $companyId) {
            $invoice = Invoice::where('company_id', $companyId)->lockForUpdate()->findOrFail($invoiceId);

            if (!$invoice->canMarkPaid()) {
                throw new \Exception('Invoice cannot be marked paid in ' . $invoice->status . ' status');
            }

            $remaining = app(PaymentService::class)->calculateRemainingDue($invoice->id);
            if ($remaining > 0.01) {
                throw new \Exception('Cannot mark paid: ₹' . number_format($remaining, 2) . ' is still outstanding. Record the payment instead.');
            }

            $invoice->update(['status' => 'paid', 'paid_date' => now()]);

            return $invoice;
        });
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

            return $this->persistTotals($newInvoice);
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
