<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PanelType;
use App\Services\QuotationService;
use Illuminate\Console\Command;

/**
 * Creates N rate-less BOQs (status 'boq') for a company, using its OWN
 * existing customers and panel types, so sales can practise the
 * BOQ → Convert → Quotation flow. Goes through QuotationService::create
 * with as_boq, exactly like the BOQ Register screen does.
 */
class SeedDemoBoqs extends Command
{
    protected $signature = 'demo:seed-boqs {--company-email=} {--count=5}';
    protected $description = 'Create rate-less demo BOQs for a company from its existing customers and panel types';

    private array $projects = [
        ['Cold Storage Expansion', 'Phase 1'], ['Pharma Clean Room', 'Block B'], ['Warehouse Roofing', 'Shed 3'],
        ['Food Processing Unit', 'Main Hall'], ['Site Office Cabins', 'Gate Area'], ['Ripening Chamber', 'Unit 2'],
    ];

    public function handle(QuotationService $quotationService): int
    {
        $email = $this->option('company-email');
        $count = max(1, min(20, (int) $this->option('count')));

        $company = $email ? Company::withoutGlobalScope('tenant')->where('email', $email)->first() : null;
        if (!$company) {
            $this->error($email ? "No company found with email {$email}." : '--company-email is required.');
            return 1;
        }

        $customers  = Customer::withoutGlobalScope('tenant')->where('company_id', $company->id)->where('is_active', true)->get();
        $panelTypes = PanelType::withoutGlobalScope('tenant')->where('company_id', $company->id)->where('is_active', true)->get();
        if ($customers->isEmpty() || $panelTypes->isEmpty()) {
            $this->error("Company {$company->id} needs at least one active customer and one active panel type.");
            return 1;
        }

        for ($i = 0; $i < $count; $i++) {
            $customer = $customers[$i % $customers->count()];
            [$project, $area] = $this->projects[$i % count($this->projects)];

            $rows = [];
            foreach ($panelTypes->shuffle()->take(mt_rand(1, min(3, $panelTypes->count()))) as $pt) {
                $roof = $pt->category === 'roof';
                $rows[] = [
                    'panel_type_id'         => $pt->id,
                    'application'           => ucfirst($pt->category ?: 'Wall'),
                    'thickness'             => collect($pt->available_thicknesses ?: [(int) ($pt->thickness ?: 50)])->random(),
                    'density_type'          => 'PUF',
                    'density_kgm3'          => 40,
                    'top_skin_material'     => 'PPGI',
                    'top_skin_thickness'    => '0.40',
                    'top_color'             => 'Off White',
                    'top_surface'           => $roof ? 'RIBBED' : 'PLAIN',
                    'bottom_skin_material'  => 'PPGI',
                    'bottom_skin_thickness' => '0.40',
                    'bottom_color'          => 'Off White',
                    'bottom_surface'        => 'PLAIN',
                    'guard_film'            => true,
                    'cello_tap'             => $roof,
                    'hsn_code'              => $pt->hsn_code ?: '39259010',
                    // rate 0 = "rate pending" — sales prices it on conversion
                    'sizes' => collect(range(1, mt_rand(2, 3)))->map(fn () => [
                        'length_mm' => mt_rand(10, 40) * 150, 'nos' => mt_rand(4, 30) * 2, 'rate_per_sqm' => 0,
                    ])->all(),
                ];
            }

            $boq = $quotationService->create([
                'company_id'       => $company->id,
                'customer_id'      => $customer->id,
                'as_boq'           => true,
                'project_name'     => "{$project} — {$area}",
                'project_location' => $customer->city,
                'quality_grade'    => 'High',
                'notes'            => 'Demo BOQ (seeded) — convert to quotation and enter rates.',
                'panel_rows'       => $rows,
            ]);

            $this->info("Created BOQ {$boq->quotation_no} for {$customer->name} ({$boq->total_sqm} sqm, " . count($rows) . ' panel rows)');
        }

        return 0;
    }
}
