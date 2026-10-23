<?php

namespace App\Console\Commands;

use App\Models\ChemicalStock;
use App\Models\CoilStock;
use App\Models\Company;
use App\Models\ConsumableStock;
use App\Models\Customer;
use App\Models\PanelType;
use App\Services\MaterialBomService;
use App\Services\OrderService;
use App\Services\ProductionRunService;
use App\Services\QuotationService;
use App\Services\StockService;
use Illuminate\Console\Command;

/**
 * Drives one job from BOQ all the way onto the shop floor, then STOPS.
 *
 *   BOQ (no rates) -> quotation -> rates entered -> sent -> accepted
 *   -> order -> production run -> started (material issued)
 *
 * It deliberately stops with the batch in_progress and its stages untouched, so
 * the next person picks it up in Production and works the stages, signs off the
 * gated ones, runs QC, dispatches and invoices by hand.
 *
 * Everything goes through the real services, so every guard, every total and
 * every stock movement behaves exactly as it does for someone clicking through
 * the screens. This is seeded data, not fabricated rows.
 *
 * Raw material is topped up first, through StockService so the movement lands in
 * the stock ledger, because starting a run deducts stock for real and most demo
 * tenants have none.
 */
class SeedProductionDemo extends Command
{
    protected $signature = 'demo:seed-production
        {--company-email= : the tenant to seed}
        {--count=1 : how many jobs to push onto the floor}
        {--no-start : stop at the order instead of starting the run}';

    protected $description = 'Seed a job from BOQ through to production in progress, ready to be worked by hand';

    public function handle(
        QuotationService $quotations,
        OrderService $orders,
        ProductionRunService $runs,
        MaterialBomService $bom,
        StockService $stock,
    ): int {
        $email = $this->option('company-email');
        $count = max(1, min(5, (int) $this->option('count')));

        $company = $email
            ? Company::withoutGlobalScope('tenant')->where('email', $email)->first()
            : null;
        if (!$company) {
            $this->error($email ? "No company found with email {$email}." : '--company-email is required.');
            return 1;
        }

        $customer = Customer::withoutGlobalScope('tenant')
            ->where('company_id', $company->id)->where('is_active', true)->first();
        $panelType = PanelType::withoutGlobalScope('tenant')
            ->where('company_id', $company->id)->where('is_active', true)->first();

        if (!$customer || !$panelType) {
            $this->error("Company {$company->id} needs at least one active customer and panel type.");
            return 1;
        }

        for ($i = 1; $i <= $count; $i++) {
            $this->line('');
            $this->info("-- Job {$i} of {$count} --------------------------------");
            $this->seedOne($company, $customer, $panelType, $quotations, $orders, $runs, $bom, $stock);
        }

        $this->line('');
        $this->info('Done. Open Production to pick these up: work each stage, sign off the gated ones, then QC, dispatch and invoice.');
        return 0;
    }

    private function seedOne(
        Company $company,
        Customer $customer,
        PanelType $panelType,
        QuotationService $quotations,
        OrderService $orders,
        ProductionRunService $runs,
        MaterialBomService $bom,
        StockService $stock,
    ): void {
        // 1. BOQ - the technical spec, deliberately without rates.
        $boq = $quotations->create([
            'company_id'       => $company->id,
            'customer_id'      => $customer->id,
            'as_boq'           => true,
            'project_name'     => 'Cold Storage - Demo Job ' . now()->format('d M H:i'),
            'project_location' => $customer->city ?: 'Ahmedabad',
            'quality_grade'    => 'High',
            'advance_pct'      => 50,
            'notes'            => 'Seeded demo job - ready to be worked through production by hand.',
            'panel_rows'       => [[
                'panel_type_id'         => $panelType->id,
                'application'           => 'Cold Room',
                'thickness'             => 100,
                'density_type'          => 'PUF',
                'density_kgm3'          => 40,
                'top_skin_material'     => 'PPGI',
                'top_skin_thickness'    => '0.50',
                'top_color'             => 'Off White',
                'top_surface'           => 'PLAIN',
                'bottom_skin_material'  => 'PPGI',
                'bottom_skin_thickness' => '0.50',
                'bottom_color'          => 'Off White',
                'bottom_surface'        => 'PLAIN',
                'guard_film'            => true,
                'fixing_system'         => 'Cam-Lock',
                'hsn_code'              => $panelType->hsn_code ?: '39259010',
                // Two lengths, one under 2000mm so the DL rule shows on the cutting sheet.
                'sizes' => [
                    ['length_mm' => 3000, 'nos' => 12, 'rate_per_sqm' => 0],
                    ['length_mm' => 1800, 'nos' => 6,  'rate_per_sqm' => 0],
                ],
            ]],
            'accessories' => [
                ['type' => 'installation', 'description' => 'Site installation', 'qty' => 1, 'rate' => 6500],
            ],
        ]);
        $this->line("  BOQ        {$boq->quotation_no}  ({$boq->total_sqm} sqm)");

        // 2. Sales picks it up: convert, price it, send, accept.
        $quotations->convertToQuotation($boq);

        $boq->load('items.sizes');
        $rates = [];
        foreach ($boq->items as $item) {
            foreach ($item->sizes as $size) {
                $rates[] = [
                    'id'           => $size->id,
                    'rate_per_sqm' => $size->length_mm >= 2000 ? 1450 : 1500,
                ];
            }
        }
        $quotations->updateSizeRates($boq, $rates);

        $quotations->send($boq->fresh());
        $quotations->accept($boq->fresh());
        $quotation = $boq->fresh();
        $this->line("  Quotation  {$quotation->quotation_no}  accepted  (total "
            . number_format((float) $quotation->total_amount, 2) . ')');

        // 3. Order.
        $order = $orders->createFromQuotation($quotation);
        $this->line("  Order      {$order->order_no}");

        if ($this->option('no-start')) {
            $this->line('  Stopped before production (--no-start).');
            return;
        }

        // 4. Make sure the floor can actually start - a run deducts stock for real.
        $this->topUpMaterial($order, $company->id, $bom, $stock);

        // 5. Push it onto the line and start it.
        $run = $runs->createFromOrders(
            $company->id,
            [$order->id],
            null,
            'Demo run - ' . $order->order_no,
            'Seeded'
        );
        $runs->start($run);

        $batch = $run->fresh('batches')->batches->first();
        $this->line("  Run        {$run->run_no}  started, material issued");
        $this->line("  Batch      " . ($batch?->batch_no ?? '?') . "  -> in progress, stages waiting for you");
    }

    /**
     * Book in whatever the BOM says this order is short of, plus a small buffer,
     * so the run starts cleanly. Goes through StockService rather than writing
     * the column directly, so each top-up appears in the stock ledger like any
     * other receipt.
     */
    private function topUpMaterial($order, int $companyId, MaterialBomService $bom, StockService $stock): void
    {
        $req = $bom->requirementForOrder($order);

        foreach ($req['lines'] as $line) {
            $short = (float) $line['short_by'];
            if ($short <= 0) {
                continue;
            }

            $qty  = round($short * 1.1, 2);   // buffer so rounding never blocks the start
            $note = 'Seeded opening stock for ' . $order->order_no;

            if ($line['material_kind'] === 'coil') {
                $row = CoilStock::withoutGlobalScope('tenant')
                    ->where('company_id', $companyId)
                    ->where('panel_type_id', $line['ref'])
                    ->first();

                if (!$row) {
                    $row = CoilStock::create([
                        'company_id'        => $companyId,
                        'panel_type_id'     => $line['ref'],
                        'quantity_in_stock' => 0,
                        'reorder_level'     => 500,
                        'unit_cost'         => 62,
                    ]);
                }

                $stock->addCoilStock($row->id, $qty, $note, $companyId);
            } elseif ($line['material_kind'] === 'chemical') {
                $row = ChemicalStock::withoutGlobalScope('tenant')
                    ->where('company_id', $companyId)
                    ->where('category', $line['ref'])
                    ->first();

                if ($row) {
                    $stock->addChemicalStock($row->id, $qty, null, null, null, $note, $companyId);
                }
            } else {
                $row = ConsumableStock::withoutGlobalScope('tenant')
                    ->where('company_id', $companyId)
                    ->where('category', $line['ref'])
                    ->first();

                if ($row) {
                    $stock->addConsumableStock($row->id, $qty, $note, $companyId);
                }
            }

            $this->line("  Stock      +{$qty} {$line['unit']} {$line['label']}");
        }
    }
}
