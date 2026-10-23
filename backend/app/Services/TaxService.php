<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TaxConfiguration;
use App\Models\TaxCalculation;
use Illuminate\Support\Facades\DB;

class TaxService
{
    /** Standard Indian GST rate for sandwich panels — used when a tenant has never configured tax. */
    public const DEFAULT_GST_RATE = 18.0;

    public function applyTaxToInvoice($invoiceId, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        return DB::transaction(function () use ($invoiceId, $companyId) {
            $invoice = Invoice::where('company_id', $companyId)
                ->with('company', 'order.customer', 'dispatch.batch.order.customer')
                ->findOrFail($invoiceId);
            $taxConfig = $this->getTaxConfiguration($companyId);

            if (!$taxConfig || !$taxConfig->is_active) {
                return $invoice;
            }

            $subtotal = $invoice->subtotal;
            $taxableAmount = $subtotal;
            $taxAmount = 0;

            if ($taxConfig->tax_type === 'exclusive') {
                $taxAmount = ($subtotal * $taxConfig->default_tax_rate) / 100;
            } else {
                $taxableAmount = ($subtotal * 100) / (100 + $taxConfig->default_tax_rate);
                $taxAmount = $subtotal - $taxableAmount;
            }

            $taxBreakdown = $this->calculateTaxBreakdown($taxConfig, $taxableAmount, $taxConfig->default_tax_rate, $invoice);

            TaxCalculation::updateOrCreate(
                ['invoice_id' => $invoiceId],
                [
                    'tax_rate' => $taxConfig->default_tax_rate,
                    'taxable_amount' => $taxableAmount,
                    'tax_amount' => round($taxAmount, 2),
                    'sgst_amount' => $taxBreakdown['sgst'] ?? 0,
                    'cgst_amount' => $taxBreakdown['cgst'] ?? 0,
                    'igst_amount' => $taxBreakdown['igst'] ?? 0
                ]
            );

            $this->updateInvoiceItemTaxes($invoiceId, $taxConfig->default_tax_rate, $taxConfig->tax_type);

            return $invoice;
        });
    }

    public function calculateTaxBreakdown($taxConfig, $taxableAmount, $taxRate, ?Invoice $invoice = null)
    {
        // Intra-state vs inter-state is decided by comparing the supplier's state
        // code (company.state_code) against the customer's state code. The old
        // implementation looked for the literal substring "GST" inside the GSTIN
        // (e.g. "27AAA…1Z5"), which never matches, so EVERY invoice was being
        // taxed as inter-state (IGST only). This is the canonical India-GST rule.
        $isIntraState = false;
        if ($invoice) {
            $customer = $invoice->order?->customer ?? $invoice->dispatch?->batch?->order?->customer;
            // Compared raw state_code strings, so "24" vs "GJ" (the same state,
            // two formats the app both accepts) read as inter-state and charged
            // IGST where the quotation had charged CGST+SGST. GstState normalises
            // both sides to the 2-digit GST code and is shared with QuotationService.
            $isIntraState = !\App\Support\GstState::isInterState($invoice->company, $customer);
        }

        if ($isIntraState) {
            $singleRate = $taxRate / 2;
            return [
                'sgst' => round(($taxableAmount * $singleRate) / 100, 2),
                'cgst' => round(($taxableAmount * $singleRate) / 100, 2),
                'igst' => 0,
            ];
        }

        return [
            'sgst' => 0,
            'cgst' => 0,
            'igst' => round(($taxableAmount * $taxRate) / 100, 2),
        ];
    }

    protected function updateInvoiceItemTaxes($invoiceId, $taxRate, $taxType)
    {
        $items = InvoiceItem::where('invoice_id', $invoiceId)->get();

        foreach ($items as $item) {
            $taxAmount = 0;

            if ($taxType === 'exclusive') {
                $taxAmount = ($item->amount * $taxRate) / 100;
                $totalWithTax = $item->amount + $taxAmount;
            } else {
                $baseAmount = ($item->amount * 100) / (100 + $taxRate);
                $taxAmount = $item->amount - $baseAmount;
                $totalWithTax = $item->amount;
            }

            $item->update([
                'tax_rate' => $taxRate,
                'tax_amount' => round($taxAmount, 2),
                'total_with_tax' => round($totalWithTax, 2)
            ]);
        }
    }

    /**
     * The tenant's tax configuration, auto-provisioning a standard 18% exclusive
     * GST setup the first time one is needed.
     *
     * Nothing in the app ever created a TaxConfiguration (/gst/register writes a
     * GstConfiguration, a different model), so this returned null for every
     * tenant and applyTaxToInvoice() bailed out early — every invoice ever
     * issued carried ₹0 GST, silently, with no warning anywhere. Falling back to
     * the same 18% the quotation engine uses makes the two agree by default.
     *
     * An existing row that the tenant deactivated is returned as-is: switching
     * tax off is a deliberate choice and must stay honoured.
     */
    public function getTaxConfiguration($companyId)
    {
        $config = TaxConfiguration::where('company_id', $companyId)->first();
        if ($config) {
            return $config;
        }

        return TaxConfiguration::create([
            'company_id'       => $companyId,
            'gst_number'       => \App\Models\Company::find($companyId)?->gstin,
            'tax_type'         => 'exclusive',
            'default_tax_rate' => self::DEFAULT_GST_RATE,
            'is_active'        => true,
        ]);
    }

    public function updateTaxConfiguration($companyId, $data)
    {
        return TaxConfiguration::updateOrCreate(
            ['company_id' => $companyId],
            [
                'gst_number' => $data['gst_number'] ?? null,
                'tax_type' => $data['tax_type'] ?? 'exclusive',
                'default_tax_rate' => $data['default_tax_rate'] ?? 0,
                'is_active' => $data['is_active'] ?? true
            ]
        );
    }

    public function validateGSTNumber($gstNumber)
    {
        if (!$gstNumber) {
            return true;
        }

        if (strlen($gstNumber) !== 15) {
            throw new \Exception('GST number must be 15 characters');
        }

        if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $gstNumber)) {
            throw new \Exception('Invalid GST number format');
        }

        return true;
    }

    public function getTaxReport($companyId, $from_date = null, $to_date = null)
    {
        $query = TaxCalculation::whereHas('invoice', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        });

        if ($from_date) {
            $query->whereDate('created_at', '>=', $from_date);
        }

        if ($to_date) {
            $query->whereDate('created_at', '<=', $to_date);
        }

        $calculations = $query->get();

        return [
            'total_taxable' => $calculations->sum('taxable_amount'),
            'total_tax' => $calculations->sum('tax_amount'),
            'total_sgst' => $calculations->sum('sgst_amount'),
            'total_cgst' => $calculations->sum('cgst_amount'),
            'total_igst' => $calculations->sum('igst_amount'),
            'calculation_count' => $calculations->count(),
            'details' => $calculations
        ];
    }
}
