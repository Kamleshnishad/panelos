<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Dispatch;
use App\Models\ProductionBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A dispatch is only meaningful with a batch behind it — invoicing reads
 * dispatch -> batch -> order -> customer to resolve the GST state pair.
 */
class DispatchFactory extends Factory
{
    protected $model = Dispatch::class;

    public function definition(): array
    {
        $company = Company::first() ?? Company::factory()->create();
        $batch   = ProductionBatch::factory()->qcPassed()->create(['company_id' => $company->id]);

        return [
            'company_id'    => $company->id,
            'batch_id'      => $batch->id,
            'dispatch_no'   => 'DISP-FAC-' . $this->faker->unique()->numerify('######'),
            'dispatch_date' => now()->toDateString(),
            'status'        => 'pending',
        ];
    }

    /**
     * Give the dispatch a real line, and the order behind it a matching item.
     * Without this every invoice built from the factory has a zero subtotal,
     * so any tax assertion silently passes against nothing.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Dispatch $dispatch) {
            $panelType = \App\Models\PanelType::where('company_id', $dispatch->company_id)->first()
                ?? \App\Models\PanelType::factory()->create(['company_id' => $dispatch->company_id]);

            $order = $dispatch->batch?->order;
            if ($order && !$order->items()->exists()) {
                $order->items()->create([
                    'panel_type_id' => $panelType->id,
                    'total_sqm'     => 50,
                    'rate_per_sqm'  => 1400,
                    'amount'        => 70000,
                    'quantity'      => 50,
                    'unit_price'    => 1400,
                    'sort_order'    => 0,
                ]);
            }

            \App\Models\DispatchItem::create([
                'dispatch_id'   => $dispatch->id,
                'panel_type_id' => $panelType->id,
                'quantity'      => 50,
                'unit_price'    => 1400,
                'amount'        => 70000,
                'created_at'    => now(),
            ]);
        });
    }
}
