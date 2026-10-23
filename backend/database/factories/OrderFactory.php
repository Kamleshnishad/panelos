<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * orders.quotation_id and customer_id are NOT NULL, so this builds the chain it
 * needs rather than leaving a test to assemble four models by hand.
 * Use ->for($company) to pin the tenant; otherwise one is reused or created.
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $company  = Company::first() ?? Company::factory()->create();
        $customer = Customer::where('company_id', $company->id)->first()
            ?? Customer::factory()->create(['company_id' => $company->id]);

        $quotation = Quotation::create([
            'company_id'   => $company->id,
            'customer_id'  => $customer->id,
            'quotation_no' => 'Q-FAC-' . $this->faker->unique()->numerify('######'),
            'status'       => 'accepted',
        ]);

        $subtotal = $this->faker->numberBetween(50000, 500000);
        $tax      = round($subtotal * 0.18, 2);

        return [
            'company_id'   => $company->id,
            'quotation_id' => $quotation->id,
            'customer_id'  => $customer->id,
            'order_no'     => 'ORD-FAC-' . $this->faker->unique()->numerify('######'),
            'order_date'   => now()->toDateString(),
            'status'       => 'pending',
            'subtotal'     => $subtotal,
            'tax_amount'   => $tax,
            'total_amount' => $subtotal + $tax,
        ];
    }
}
