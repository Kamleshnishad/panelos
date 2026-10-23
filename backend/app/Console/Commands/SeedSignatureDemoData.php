<?php

namespace App\Console\Commands;

use App\Models\ChemicalStock;
use App\Models\CoilStock;
use App\Models\Company;
use App\Models\ConsumableStock;
use App\Models\Customer;
use App\Models\PanelType;
use App\Models\ProductionStage;
use App\Models\User;
use App\Services\BatchStageLogService;
use App\Services\DispatchService;
use App\Services\InvoiceService;
use App\Services\LeadService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ProductionBatchService;
use App\Services\QualityControlService;
use App\Services\QuotationService;
use Illuminate\Console\Command;

/**
 * Pre-configures a company's real product catalog (panel types + accessories,
 * from signaturepufpanel.com's own published product lines and the HSN code
 * on their own Proforma Invoice) and seeds ONE realistic, fully end-to-end
 * customer transaction so every module has a demo-able entry.
 *
 * The transaction itself is reconstructed from a real Proforma Invoice +
 * BOQ (Uma Signature Puf Panel LLP -> Kwality Business Associate LLP,
 * 27/11/23) — same panel spec, same sizes, same rates, same accessories,
 * same advance amount. Idempotent per --company-email: re-running skips
 * anything that already exists by its natural key (panel type name,
 * accessory name, customer email) rather than erroring or duplicating.
 *
 * Driven entirely through the real service layer (QuotationService,
 * OrderService, ProductionBatchService, etc.) — not raw inserts — so every
 * computed field (GST, totals, stock deductions, status transitions) is
 * exactly what the app itself would produce for a real user doing this.
 */
class SeedSignatureDemoData extends Command
{
    protected $signature = 'demo:seed-signature {--company-email=}';
    protected $description = 'Seed the Signature PUF Panel product catalog and one realistic end-to-end demo customer';

    public function handle(
        QuotationService $quotationService,
        OrderService $orderService,
        ProductionBatchService $batchService,
        BatchStageLogService $stageLogService,
        QualityControlService $qcService,
        DispatchService $dispatchService,
        InvoiceService $invoiceService,
        PaymentService $paymentService,
        LeadService $leadService,
    ): int {
        $email = $this->option('company-email');
        if (!$email) {
            $this->error('--company-email is required.');
            return 1;
        }

        $company = Company::withoutGlobalScope('tenant')->where('email', $email)->first();
        if (!$company) {
            $this->error("No company found with email {$email}.");
            return 1;
        }
        $admin = User::withoutGlobalScope('tenant')->where('company_id', $company->id)->where('is_company_admin', true)->first();
        if (!$admin) {
            $this->error("No admin user found for company {$company->id}.");
            return 1;
        }

        // GST inter-state detection (QuotationService::detectInterState) reads
        // the company's GSTIN prefix, not state_code directly — without it,
        // every quotation silently computes CGST+SGST even for a genuinely
        // inter-state customer. Verified first-party from the company's own
        // Proforma Invoice letterhead (not the earlier third-party IndiaMART
        // listing) — only set if not already entered, never overwrite.
        if (!$company->gstin) {
            $company->update(['gstin' => '24AAFFU9050M1ZS']);
        }

        // ── 1. Product catalog — real lines from signaturepufpanel.com ─────
        $panelTypes = $this->seedPanelTypes($company->id);
        $accessories = $this->seedAccessories($company->id);
        $this->seedProductionStages($company->id);
        $this->info('Catalog ready: ' . count($panelTypes) . ' panel types, ' . count($accessories) . ' accessories.');

        $roofPanel = $panelTypes['Signature TopRoof™'];
        $this->seedStock($company->id, $roofPanel->id);

        // Safe to re-run: if a previous run got partway through the
        // transaction chain (e.g. failed on a later step), wipe everything
        // for this one demo customer and rebuild from scratch rather than
        // erroring on "quotation already exists" / "order already exists".
        $this->resetExistingDemoCustomer($company->id);

        // ── 2. Customer (from the real Proforma Invoice's buyer block) ─────
        $customer = Customer::withoutGlobalScope('tenant')->firstOrCreate(
            ['company_id' => $company->id, 'email' => 'accounts@kwalitybusinessassociate.in'],
            [
                'name' => 'Kwality Business Associate LLP',
                'code' => 'KWAL001',
                'type' => 'corporate',
                'contact_person' => 'Kanhaiya Lal Laddha',
                'phone' => '9574799722',
                'address_line1' => 'Jai Ambe Furniture, Near Manak Chowk Sabji Mandi, Veer Savarkar Marg',
                'address_line2' => '100 Feet Road, Rajnagar',
                'city' => 'Rajsamand',
                'state' => 'Rajasthan',
                'state_code' => '08',
                'pincode' => '313324',
                'country' => 'India',
                'credit_limit' => 500000,
                'outstanding_balance' => 0,
                'payment_terms_days' => 30,
                'is_active' => true,
            ]
        );

        // ── 3. Lead -> won (CRM module) ─────────────────────────────────────
        $lead = $leadService->create($company->id, [
            'contact_name' => 'Kanhaiya Lal Laddha',
            'company_name' => 'Kwality Business Associate LLP',
            'phone' => '9574799722',
            'city' => 'Rajsamand',
            'source' => 'Referral',
            'requirement' => '30mm PUF roof panels for a PEB shed, approx 310 SQM, plus roof side cap and ridge accessories.',
            'application' => 'Roof',
            'est_qty_sqm' => 312.93,
            'est_value' => 422235,
            'customer_id' => $customer->id,
        ]);
        // Not 'quoted'/'won' yet — QuotationService::create() below links this
        // lead to the quotation it creates, and LeadService::linkQuotation()
        // itself sets status to 'quoted' as part of that (correctly — a lead
        // doesn't jump to "quoted" before a quotation exists). 'won' comes
        // after the quotation is actually accepted, further down.
        foreach (['contacted', 'qualified'] as $status) {
            $lead = $leadService->changeStatus($lead, $status, null, $admin->id);
        }

        // ── 4. Quotation (exact panel spec + sizes + accessories from the PDF) ──
        $quotation = $quotationService->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'lead_id' => $lead->id,
            'quality_grade' => 'High',
            'validity_days' => 10,
            'discount_pct' => 0,
            'transport_fixed' => false,
            // 150000 advance on a ~422235 total is what the real PFI shows —
            // not the usual round 50%.
            'advance_pct' => 35.52,
            'notes' => 'Payment: 50% advance with order, balance before dispatch. Delivery: 14 days from techno-commercial clearance. Warranty: 12 months against manufacturing defects.',
            'panel_rows' => [[
                'panel_type_id' => $roofPanel->id,
                'application' => 'Roof',
                'thickness' => 30,
                'density_type' => 'PUF',
                'density_kgm3' => 40,
                'top_skin_material' => 'PPGL',
                'top_skin_thickness' => '0.40',
                'top_color' => 'Off White',
                'top_surface' => 'RIBBED',
                'bottom_skin_material' => 'PPGL',
                'bottom_skin_thickness' => '0.40',
                'bottom_color' => 'Off White',
                'bottom_surface' => 'PLAIN',
                'guard_film' => true,
                'cello_tap' => true,
                'hsn_code' => '39259010',
                'sizes' => [
                    ['length_mm' => 3660, 'nos' => 60, 'rate_per_sqm' => 1050],
                    ['length_mm' => 3355, 'nos' => 24, 'rate_per_sqm' => 1050],
                    ['length_mm' => 4270, 'nos' => 3, 'rate_per_sqm' => 1050],
                ],
            ]],
            'accessories' => [
                ['type' => 'standard', 'accessory_id' => $accessories['Roof Side Cap']->id, 'qty' => 55, 'rate' => 150, 'unit' => 'MTR'],
                ['type' => 'standard', 'accessory_id' => $accessories['Ridges Inner & Outer']->id, 'qty' => 60, 'rate' => 350, 'unit' => 'MTR'],
            ],
        ]);
        $quotation = $quotationService->send($quotation);
        $quotation = $quotationService->accept($quotation);
        $leadService->changeStatus($lead->fresh(), 'won', null, $admin->id);
        $this->info("Quotation {$quotation->quotation_no} created and accepted — total ₹" . number_format((float) $quotation->total_amount, 2));

        // ── 5. Order ─────────────────────────────────────────────────────────
        $order = $orderService->createFromQuotation($quotation, true);
        $this->info("Order {$order->order_no} created.");

        // ── 6. Production batch (self-issues its own material — no run) ────
        $batch = $batchService->createFromOrder($order, []);
        $batch = $batchService->startProduction($batch, true); // force: demo stock is a reasonable but not exact match

        foreach (ProductionStage::where('company_id', $company->id)->orderBy('sequence')->get() as $stage) {
            $log = $stageLogService->startStage($batch, $stage, $admin->id);
            $stageLogService->completeStage($log, $admin->id);
        }
        $batch = $batchService->completeBatch($batch, ['completed_quantity' => 312.93]);
        $this->info("Batch {$batch->batch_no} walked through " . ProductionStage::where('company_id', $company->id)->count() . ' stages and completed.');

        // ── 7. QC ────────────────────────────────────────────────────────────
        $qc = $qcService->create($batch, ['status' => 'pass', 'notes' => 'Visual and dimensional check passed. No delamination or core defects.'], $admin->id);
        $qcService->approve($qc, $admin->id, 'Cleared for dispatch.');

        // ── 8. Dispatch ──────────────────────────────────────────────────────
        $dispatch = $dispatchService->createDispatch($batch->id, [
            'customer_address' => $customer->address_line1 . ', ' . $customer->city . ', ' . $customer->state . ' - ' . $customer->pincode,
            'tracking_number' => 'TRK-KWAL-001',
        ], $company->id);
        $dispatchService->allocateStockForDispatch($dispatch->id, $company->id);
        $dispatchService->completeDispatch($dispatch->id, [], $company->id);
        $this->info("Dispatch {$dispatch->dispatch_no} delivered.");

        // ── 9. Invoice + partial (advance) payment ──────────────────────────
        $invoice = $invoiceService->createFromDispatch($dispatch->id, [
            'notes' => 'GST and TCS extra as applicable. Warranty 12 months against manufacturing defects.',
        ], $company->id);
        $invoiceService->sendInvoice($invoice->id, $company->id);
        $paymentService->recordPayment($invoice->id, 150000, 'bank_transfer', 'ADV-KWAL-271123', $company->id);
        $this->info("Invoice {$invoice->invoice_no} sent with advance payment of ₹1,50,000 recorded — balance due shows the realistic remainder.");

        $this->info('');
        $this->info('✅ Demo data ready: Lead -> Quotation -> Order -> Batch -> QC -> Dispatch -> Invoice -> Payment, all for Kwality Business Associate LLP.');
        return 0;
    }

    /** @return array<string, PanelType> keyed by name */
    private function seedPanelTypes(int $companyId): array
    {
        // Specs pulled directly from each product's own page on
        // signaturepufpanel.com (fetched 2026-10-23). HSN 39259010 is the
        // code shown on the company's own real Proforma Invoice for a PUF
        // roof panel — applied to all lines since none of the product pages
        // list their own HSN.
        // thermal_resistance (m²K/W) is the R-value at the thinnest listed
        // thickness, PU core, taken from each product's own spec table on
        // signaturepufpanel.com where given; where the page didn't list an
        // R-value at that exact thickness (CoolRoof, ColdCore Wall, SeamLex
        // Wall), it's a same-core/comparable-thickness estimate, not a
        // verified figure — flagged per line below.
        $defs = [
            ['name' => 'Signature TopRoof™', 'code' => 'SIG-TOPROOF', 'category' => 'roof', 'r' => 1.5,
                'description' => 'Insulated roof sandwich panel (PU/PIR core) for industrial sheds, warehouses, and PEB structures.',
                'thicknesses' => [30, 40, 50, 60, 80, 100, 120], 'width' => 1000, 'length' => 6000],
            ['name' => 'Signature CoolRoof™', 'code' => 'SIG-COOLROOF', 'category' => 'roof', 'r' => 0.5, // estimate — no R given for 10mm
                'description' => 'Lightweight 10mm roof panel (PU/PIR core) for temporary structures and factory sheds.',
                'thicknesses' => [10], 'width' => 1000, 'length' => 6000],
            ['name' => 'Signature SolarSeam™ Roof', 'code' => 'SIG-SOLARSEAM-ROOF', 'category' => 'roof', 'r' => 1.25, // estimate from typical rockwool λ
                'description' => 'High-density rockwool (FireX) roof panel built for solar-mounted and fire-rated roofing.',
                'thicknesses' => [50, 80, 100], 'width' => 1000, 'length' => 8000],
            ['name' => 'Signature SeamLex™', 'code' => 'SIG-SEAMLEX', 'category' => 'roof', 'r' => 2.2,
                'description' => 'Concealed-fix PU/PIR panel for industrial, cold storage, clean room, and data-center roofing.',
                'thicknesses' => [50, 80, 100], 'width' => 1000, 'length' => 6000],
            ['name' => 'Signature Wall Pro™', 'code' => 'SIG-WALLPRO', 'category' => 'wall', 'r' => 1.5,
                'description' => 'Standard architectural wall panel (PU/PIR core) with tongue-and-groove interlocking joint.',
                'thicknesses' => [30, 40, 50, 60], 'width' => 1000, 'length' => 6000],
            ['name' => 'Signature ColdLock™', 'code' => 'SIG-COLDLOCK', 'category' => 'wall', 'r' => 4.1,
                'description' => 'Cam-lock wall panel (PU/PIR core) with airtight joints for cold storage and temperature-controlled facilities.',
                'thicknesses' => [100, 120, 150], 'width' => 1100, 'length' => 7500],
            ['name' => 'Signature ColdCore™ Wall', 'code' => 'SIG-COLDCORE-WALL', 'category' => 'wall', 'r' => 3.4, // estimate, interpolated
                'description' => 'Thermal insulation wall system (PU/PIR core) for strict climate-control applications.',
                'thicknesses' => [80, 100, 120, 150], 'width' => 1000, 'length' => 7500],
            ['name' => 'Signature SeamLex™ Wall', 'code' => 'SIG-SEAMLEX-WALL', 'category' => 'wall', 'r' => 1.25, // estimate from typical rockwool λ
                'description' => 'Secret-fix, hidden-screw rockwool wall panel (A1 non-combustible) for fire-rated applications.',
                'thicknesses' => [50, 80, 100, 150], 'width' => 1000, 'length' => 8000],
        ];

        $result = [];
        foreach ($defs as $d) {
            $result[$d['name']] = PanelType::withoutGlobalScope('tenant')->firstOrCreate(
                ['company_id' => $companyId, 'name' => $d['name']],
                [
                    'code' => $d['code'],
                    'category' => $d['category'],
                    'hsn_code' => '39259010',
                    'available_thicknesses' => $d['thicknesses'],
                    'description' => $d['description'],
                    'thickness' => $d['thicknesses'][0],
                    'width' => $d['width'],
                    'length' => $d['length'],
                    'thermal_resistance' => $d['r'],
                    // Not publicly listed anywhere — 0 (not a guessed price)
                    // rather than inventing a number; real rates are entered
                    // per-quotation same as every other panel type.
                    'base_price' => 0,
                    'is_active' => true,
                ]
            );
        }
        return $result;
    }

    /** @return array<string, \App\Models\Accessory> keyed by name */
    private function seedAccessories(int $companyId): array
    {
        // Rates verified against the company's own real Proforma Invoice line items.
        $defs = [
            ['name' => 'Roof Side Cap', 'code' => 'ACC-ROOFCAP', 'unit' => 'MTR', 'rate' => 150],
            ['name' => 'Ridges Inner & Outer', 'code' => 'ACC-RIDGES', 'unit' => 'MTR', 'rate' => 350],
        ];
        $result = [];
        foreach ($defs as $d) {
            $result[$d['name']] = \App\Models\Accessory::withoutGlobalScope('tenant')->firstOrCreate(
                ['company_id' => $companyId, 'name' => $d['name']],
                ['code' => $d['code'], 'unit' => $d['unit'], 'rate' => $d['rate'], 'unit_price' => $d['rate'], 'is_active' => true]
            );
        }
        return $result;
    }

    private function seedProductionStages(int $companyId): void
    {
        $stages = [
            ['name' => 'Raw Material Inspection', 'sequence' => 1],
            ['name' => 'Sheet Cutting', 'sequence' => 2],
            ['name' => 'Foam Injection', 'sequence' => 3],
            ['name' => 'Curing', 'sequence' => 4],
            ['name' => 'Quality Check', 'sequence' => 5],
            ['name' => 'Packaging', 'sequence' => 6],
        ];
        foreach ($stages as $s) {
            ProductionStage::withoutGlobalScope('tenant')->firstOrCreate(
                ['company_id' => $companyId, 'name' => $s['name']],
                ['sequence' => $s['sequence'], 'is_active' => true]
            );
        }
    }

    /**
     * Deletes this one demo customer and everything downstream of it (lead,
     * quotation, order, batch, QC, dispatch, invoice, payment) so the whole
     * command can be safely re-run after a partial failure, without touching
     * the product catalog, stock, or any other customer.
     */
    private function resetExistingDemoCustomer(int $companyId): void
    {
        $customer = Customer::withoutGlobalScope('tenant')
            ->where('company_id', $companyId)->where('name', 'Kwality Business Associate LLP')->first();
        if (!$customer) return;

        $quotationIds = \App\Models\Quotation::withoutGlobalScope('tenant')->where('company_id', $companyId)->where('customer_id', $customer->id)->pluck('id');
        $orderIds = \App\Models\Order::withoutGlobalScope('tenant')->where('company_id', $companyId)->where('customer_id', $customer->id)->pluck('id');
        $batchIds = \App\Models\ProductionBatch::withoutGlobalScope('tenant')->where('company_id', $companyId)->whereIn('order_id', $orderIds)->pluck('id');
        $dispatchIds = \App\Models\Dispatch::withoutGlobalScope('tenant')->where('company_id', $companyId)->whereIn('batch_id', $batchIds)->pluck('id');
        $invoiceIds = \App\Models\Invoice::withoutGlobalScope('tenant')->where('company_id', $companyId)->whereIn('dispatch_id', $dispatchIds)->pluck('id');

        // These are plain Model classes (no SoftDeletes trait), so forceDelete()
        // isn't available on their builder — delete() is already a hard delete.
        \App\Models\PaymentTransaction::whereIn('invoice_id', $invoiceIds)->delete();
        \App\Models\TaxCalculation::whereIn('invoice_id', $invoiceIds)->delete();
        \App\Models\InvoiceItem::whereIn('invoice_id', $invoiceIds)->delete();
        \App\Models\Invoice::withoutGlobalScope('tenant')->whereIn('id', $invoiceIds)->forceDelete();
        \App\Models\StockAllocation::whereIn('dispatch_id', $dispatchIds)->delete();
        \App\Models\DispatchItem::whereIn('dispatch_id', $dispatchIds)->delete();
        \App\Models\Dispatch::withoutGlobalScope('tenant')->whereIn('id', $dispatchIds)->forceDelete();
        \App\Models\QualityControl::whereIn('batch_id', $batchIds)->delete();
        \App\Models\BatchStageLog::whereIn('batch_id', $batchIds)->delete();
        \App\Models\ProductionMaterialUsage::where('company_id', $companyId)->whereIn('batch_id', $batchIds)->forceDelete();
        \App\Models\ProductionBatch::withoutGlobalScope('tenant')->whereIn('id', $batchIds)->forceDelete();
        \App\Models\OrderItemSize::whereIn('order_item_id', \App\Models\OrderItem::whereIn('order_id', $orderIds)->pluck('id'))->delete();
        \App\Models\OrderItem::whereIn('order_id', $orderIds)->delete();
        \App\Models\Order::withoutGlobalScope('tenant')->whereIn('id', $orderIds)->forceDelete();
        \App\Models\QuotationItemSize::whereIn('quotation_item_id', \App\Models\QuotationItem::whereIn('quotation_id', $quotationIds)->pluck('id'))->delete();
        \App\Models\QuotationItem::whereIn('quotation_id', $quotationIds)->delete();
        \Illuminate\Support\Facades\DB::table('quotation_accessories')->whereIn('quotation_id', $quotationIds)->delete();
        \App\Models\Quotation::withoutGlobalScope('tenant')->whereIn('id', $quotationIds)->forceDelete();
        \App\Models\LeadActivity::whereIn('lead_id', \App\Models\Lead::withoutGlobalScope('tenant')->where('company_id', $companyId)->where('customer_id', $customer->id)->pluck('id'))->forceDelete();
        \App\Models\Lead::withoutGlobalScope('tenant')->where('company_id', $companyId)->where('customer_id', $customer->id)->forceDelete();
        $customer->forceDelete();

        $this->info('Removed a previous partial/complete run for this demo customer — rebuilding from scratch.');
    }

    /** Enough stock for the batch to self-issue material without needing --force. */
    private function seedStock(int $companyId, int $panelTypeId): void
    {
        CoilStock::withoutGlobalScope('tenant')->firstOrCreate(
            ['company_id' => $companyId, 'panel_type_id' => $panelTypeId],
            ['quantity_in_stock' => 10000, 'reorder_level' => 500]
        );
        foreach (['Polyol', 'Isocyanate'] as $category) {
            ChemicalStock::withoutGlobalScope('tenant')->firstOrCreate(
                ['company_id' => $companyId, 'category' => $category],
                ['name' => $category, 'quantity_in_stock' => 5000, 'unit' => 'kg', 'reorder_level' => 200]
            );
        }
        foreach (['film' => 'Protective Film', 'tape' => 'Sealant Tape'] as $category => $name) {
            ConsumableStock::withoutGlobalScope('tenant')->firstOrCreate(
                ['company_id' => $companyId, 'category' => $category],
                ['name' => $name, 'unit' => $category === 'film' ? 'sqm' : 'm', 'quantity_in_stock' => 2000, 'reorder_level' => 100]
            );
        }
    }
}
