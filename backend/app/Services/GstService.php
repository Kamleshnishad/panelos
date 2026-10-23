<?php

namespace App\Services;

use App\Models\GstConfiguration;
use App\Models\HsnCode;
use App\Models\GstTaxBreakdown;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

class GstService
{
    // Indian States and UT codes
    protected $stateMap = [
        'AN' => 'Andaman and Nicobar Islands',
        'AP' => 'Andhra Pradesh',
        'AR' => 'Arunachal Pradesh',
        'AS' => 'Assam',
        'BR' => 'Bihar',
        'CG' => 'Chhattisgarh',
        'CH' => 'Chandigarh',
        'CT' => 'Chhattisgarh',
        'DD' => 'Daman and Diu',
        'DL' => 'Delhi',
        'DN' => 'Dadra and Nagar Haveli',
        'GA' => 'Goa',
        'GJ' => 'Gujarat',
        'HR' => 'Haryana',
        'HP' => 'Himachal Pradesh',
        'JK' => 'Jammu and Kashmir',
        'JH' => 'Jharkhand',
        'KA' => 'Karnataka',
        'KL' => 'Kerala',
        'LA' => 'Ladakh',
        'LD' => 'Lakshadweep',
        'MH' => 'Maharashtra',
        'ML' => 'Meghalaya',
        'MN' => 'Manipur',
        'MP' => 'Madhya Pradesh',
        'MZ' => 'Mizoram',
        'NL' => 'Nagaland',
        'OD' => 'Odisha',
        'OR' => 'Odisha',
        'PB' => 'Punjab',
        'PY' => 'Puducherry',
        'RJ' => 'Rajasthan',
        'SK' => 'Sikkim',
        'TG' => 'Telangana',
        'TR' => 'Tripura',
        'UP' => 'Uttar Pradesh',
        'UT' => 'Uttarakhand',
        'WB' => 'West Bengal',
    ];

    // Standard GST rates in India
    protected $standardRates = [0, 5, 12, 18, 28];

    public function registerGstConfiguration($companyId, $stateCode, $gstin, $registrationType = 'regular')
    {
        try {
            // Make new registration primary if it's the first one
            $isPrimary = !GstConfiguration::byCompany($companyId)->exists();

            // If making new config primary, set others to non-primary
            if ($isPrimary) {
                GstConfiguration::byCompany($companyId)->update(['is_primary' => false]);
            }

            $config = GstConfiguration::create([
                'company_id' => $companyId,
                'state_code' => strtoupper($stateCode),
                // Was keyed by alpha code only, so a numeric GST code ("24")
                // always stored "Unknown". GstState resolves either format.
                'state_name' => \App\Support\GstState::name($stateCode)
                    ?? ($this->stateMap[strtoupper($stateCode)] ?? 'Unknown'),
                'gstin' => $gstin,
                'registration_type' => $registrationType,
                'is_primary' => $isPrimary,
                'is_active' => true,
            ]);

            Log::info('GST configuration registered', [
                'company_id' => $companyId,
                'state_code' => $stateCode,
                'gstin' => $gstin
            ]);

            return ['success' => true, 'data' => $config];
        } catch (\Exception $e) {
            Log::error('Failed to register GST configuration', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function addHsnCode($companyId, $code, $description, $category, $gstRate, $cessRate = 0)
    {
        try {
            if (!in_array($gstRate, $this->standardRates)) {
                return ['success' => false, 'message' => 'Invalid GST rate. Must be 0, 5, 12, 18, or 28'];
            }

            $hsn = HsnCode::create([
                'company_id' => $companyId,
                'code' => $code,
                'description' => $description,
                'category' => $category,
                'gst_rate' => $gstRate,
                'cess_rate' => $cessRate,
                'is_active' => true,
            ]);

            return ['success' => true, 'data' => $hsn];
        } catch (\Exception $e) {
            Log::error('Failed to add HSN code', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function calculateGst(Invoice $invoice, $companyId, $gstRate = null)
    {
        try {
            $supplier = $this->getPrimaryGstConfig($companyId);
            $customer = $this->getCustomerState($invoice);

            if (!$supplier || !$customer) {
                return $this->calculateSimpleGst($invoice, $gstRate);
            }

            // PB-005 was fixed in TaxService and QuotationService but this third
            // path was missed: a raw string compare makes 'MH' and '27' — the same
            // state written two ways — read as inter-state, so /calculate-gst could
            // charge IGST where the invoice itself charged CGST+SGST. One helper,
            // one answer, everywhere.
            $supplierState = \App\Support\GstState::normalize($supplier->state_code)
                ?? \App\Support\GstState::normalize($supplier->gstin ?? null);
            $customerState = \App\Support\GstState::normalize($customer);
            $isIntraState = $supplierState === null || $customerState === null
                ? true                                   // unknown -> intra-state, as everywhere else
                : $supplierState === $customerState;
            $subtotal = $invoice->subtotal;

            // Determine GST rate
            $rate = $gstRate ?? $this->getDefaultGstRate($companyId);

            // Calculate tax amount
            $taxAmount = ($subtotal * $rate) / 100;

            // Determine SGST, CGST, IGST
            $sgstAmount = 0;
            $cgstAmount = 0;
            $igstAmount = 0;
            $cessAmount = 0;

            if ($isIntraState) {
                // Intra-state: SGST + CGST (50-50 split)
                $sgstAmount = $taxAmount / 2;
                $cgstAmount = $taxAmount / 2;
            } else {
                // Inter-state: IGST only
                $igstAmount = $taxAmount;
            }

            $breakdown = GstTaxBreakdown::create([
                'invoice_id' => $invoice->id,
                'company_id' => $companyId,
                'transaction_type' => $this->determineTransactionType($invoice),
                'gst_rate' => $rate,
                'sgst_amount' => $sgstAmount,
                'cgst_amount' => $cgstAmount,
                'igst_amount' => $igstAmount,
                'cess_amount' => $cessAmount,
                'total_tax_amount' => $taxAmount,
                'supplier_state' => $supplierState,
                'customer_state' => $customerState,
                'is_reverse_charge' => false,
            ]);

            return [
                'success' => true,
                'tax_rate' => $rate,
                'tax_amount' => $taxAmount,
                'sgst_amount' => $sgstAmount,
                'cgst_amount' => $cgstAmount,
                'igst_amount' => $igstAmount,
                'cess_amount' => $cessAmount,
                'is_intra_state' => $isIntraState,
                'transaction_type' => $breakdown->transaction_type,
                'breakdown_id' => $breakdown->id,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to calculate GST', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getGstBreakdown($invoiceId, $companyId = null)
    {
        try {
            $query = GstTaxBreakdown::where('invoice_id', $invoiceId);

            // Tenant guard: only return a breakdown whose invoice belongs to the company.
            if ($companyId !== null) {
                $query->whereHas('invoice', fn ($q) => $q->where('company_id', $companyId));
            }

            $breakdown = $query->first();

            if (!$breakdown) {
                // A GstTaxBreakdown row only exists once /calculate-gst has been
                // called explicitly. Every invoice still has a TaxCalculation
                // written by TaxService at creation, so fall back to that rather
                // than 404-ing on an invoice that plainly does have GST on it.
                $invoice = \App\Models\Invoice::when($companyId !== null,
                        fn ($q) => $q->where('company_id', $companyId))
                    ->with('taxCalculation')->find($invoiceId);

                if (!$invoice) {
                    return ['success' => false, 'message' => 'GST breakdown not found'];
                }

                $calc = $invoice->taxCalculation;

                return [
                    'success' => true,
                    'data' => [
                        'invoice_id'     => (int) $invoiceId,
                        'source'         => 'tax_calculation',
                        'taxable_amount' => (float) ($calc->taxable_amount ?? $invoice->subtotal),
                        'tax_rate'       => (float) ($calc->tax_rate ?? 0),
                        'cgst_amount'    => (float) ($calc->cgst_amount ?? 0),
                        'sgst_amount'    => (float) ($calc->sgst_amount ?? 0),
                        'igst_amount'    => (float) ($calc->igst_amount ?? 0),
                        'total_tax'      => (float) ($calc->tax_amount ?? 0),
                    ],
                ];
            }

            return [
                'success' => true,
                'data' => $breakdown
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getCompanyGstConfigurations($companyId)
    {
        try {
            $configs = GstConfiguration::byCompany($companyId)->get();

            return [
                'success' => true,
                'data' => $configs
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function generateGstReport($companyId, $startDate = null, $endDate = null)
    {
        try {
            $query = GstTaxBreakdown::byCompany($companyId);

            if ($startDate && $endDate) {
                $query->whereBetween('created_at', [$startDate, $endDate]);
            }

            $breakdowns = $query->get();

            $report = [
                'total_invoices' => $breakdowns->count(),
                'intra_state_invoices' => $breakdowns->where('supplier_state', '=', 'customer_state')->count(),
                'inter_state_invoices' => $breakdowns->where('supplier_state', '!=', 'customer_state')->count(),
                'total_sgst' => $breakdowns->sum('sgst_amount'),
                'total_cgst' => $breakdowns->sum('cgst_amount'),
                'total_igst' => $breakdowns->sum('igst_amount'),
                'total_cess' => $breakdowns->sum('cess_amount'),
                'total_tax' => $breakdowns->sum('total_tax_amount'),
                'rate_breakdown' => $breakdowns->groupBy('gst_rate')->map(function ($items) {
                    return [
                        'rate' => $items->first()->gst_rate,
                        'count' => $items->count(),
                        'tax_amount' => $items->sum('total_tax_amount'),
                    ];
                }),
            ];

            return ['success' => true, 'data' => $report];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getGstCompliance($companyId)
    {
        try {
            $breakdowns = GstTaxBreakdown::byCompany($companyId)->get();

            $sgstTotal = $breakdowns->sum('sgst_amount');
            $cgstTotal = $breakdowns->sum('cgst_amount');
            $igstTotal = $breakdowns->sum('igst_amount');
            $cessTotal = $breakdowns->sum('cess_amount');

            $compliance = [
                'sgst_payable' => $sgstTotal,
                'cgst_payable' => $cgstTotal,
                'igst_payable' => $igstTotal,
                'cess_payable' => $cessTotal,
                'total_gst_payable' => $sgstTotal + $cgstTotal + $igstTotal + $cessTotal,
                'intra_state_count' => $breakdowns->filter(function ($b) {
                    return $b->supplier_state === $b->customer_state;
                })->count(),
                'inter_state_count' => $breakdowns->filter(function ($b) {
                    return $b->supplier_state !== $b->customer_state;
                })->count(),
            ];

            return ['success' => true, 'data' => $compliance];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function validateGstin($gstin, $stateCode = null)
    {
        // Basic GSTIN validation
        if (!preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z]{1}\d[Z]{1}[A-Z\d]{1}$/', $gstin)) {
            return ['valid' => false, 'message' => 'Invalid GSTIN format'];
        }

        // Same PB-005 root cause again: this compared the GSTIN's numeric prefix
        // ('27') against whatever the caller passed as a state code, which is
        // often alpha ('MH'), so a perfectly valid GSTIN was rejected as
        // mismatched. Normalise both sides first. (The old loop that computed
        // $stateFound was dead — nothing ever read it.)
        $stateFromGstin = \App\Support\GstState::normalize(substr($gstin, 0, 2));
        if ($stateFromGstin === null) {
            return ['valid' => false, 'message' => 'GSTIN does not start with a known state code'];
        }

        if ($stateCode && \App\Support\GstState::normalize($stateCode) !== $stateFromGstin) {
            return ['valid' => false, 'message' => 'State code in GSTIN does not match provided state'];
        }

        return ['valid' => true, 'message' => 'Valid GSTIN'];
    }

    protected function calculateSimpleGst(Invoice $invoice, $gstRate = null)
    {
        $rate = $gstRate ?? 18; // Default 18%
        $subtotal = $invoice->subtotal;
        $taxAmount = ($subtotal * $rate) / 100;

        return [
            'success' => true,
            'tax_rate' => $rate,
            'tax_amount' => $taxAmount,
            'sgst_amount' => $taxAmount / 2,
            'cgst_amount' => $taxAmount / 2,
            'igst_amount' => 0,
            'cess_amount' => 0,
            'is_intra_state' => true,
        ];
    }

    protected function getPrimaryGstConfig($companyId)
    {
        return GstConfiguration::byCompany($companyId)->primary()->first();
    }

    protected function getCustomerState(Invoice $invoice)
    {
        $customer = $invoice->dispatch?->batch?->order?->customer
            ?? $invoice->order?->customer;

        // state_code first (canonical 2-digit), then the free-text state name,
        // then the GSTIN prefix — GstState::normalize handles all three.
        return $customer?->state_code ?: ($customer?->state ?: $customer?->gstin);
    }

    protected function getDefaultGstRate($companyId)
    {
        return 18; // Default to 18% GST
    }

    protected function determineTransactionType(Invoice $invoice)
    {
        // Can be extended to detect B2C, B2G, etc.
        return 'B2B';
    }

    /** Canonical list for state pickers: 2-digit GST code => state name. */
    public function getStatesList()
    {
        return \App\Support\GstState::all();
    }
}
