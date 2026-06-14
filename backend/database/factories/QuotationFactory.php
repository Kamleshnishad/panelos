<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuotationFactory extends Factory
{
    protected $model = Quotation::class;

    public function definition(): array
    {
        $company = Company::first() ?? Company::factory()->create();
        $customer = Customer::factory()->for($company)->create();

        return [
            'company_id' => $company->id,
            'quotation_no' => 'Q-' . date('Y') . '-' . strtoupper($this->faker->unique()->regexify('[0-9]{6}')),
            'customer_id' => $customer->id,
            'status' => 'draft',
            'subtotal' => $this->faker->randomFloat(2, 5000, 50000),
            'tax_amount' => 0,
            'total_amount' => 0,
            'quoted_on' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'sent_at' => null,
            'accepted_at' => null,
            'notes' => $this->faker->optional()->sentence(),
        ];
    }

    public function sent(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'sent',
                'sent_at' => now(),
            ];
        });
    }

    public function accepted(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'accepted',
                'sent_at' => now(),
                'accepted_at' => now(),
            ];
        });
    }

    public function rejected(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'rejected',
                'sent_at' => now(),
            ];
        });
    }
}
