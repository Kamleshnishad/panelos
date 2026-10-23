<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Order;
use App\Models\ProductionBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductionBatchFactory extends Factory
{
    protected $model = ProductionBatch::class;

    public function definition(): array
    {
        $company = Company::first() ?? Company::factory()->create();
        $order   = Order::where('company_id', $company->id)->first()
            ?? Order::factory()->create(['company_id' => $company->id]);

        return [
            'company_id'         => $company->id,
            'order_id'           => $order->id,
            'batch_no'           => 'PB-FAC-' . $this->faker->unique()->numerify('######'),
            'status'             => 'draft',
            'planned_quantity'   => $this->faker->numberBetween(20, 200),
            'completed_quantity' => 0,
        ];
    }

    public function qcPassed(): static
    {
        return $this->state(fn () => ['status' => 'qc_passed', 'completed_quantity' => 50]);
    }
}
