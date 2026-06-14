<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $company = Company::first() ?? Company::factory()->create();

        return [
            'company_id' => $company->id,
            'name' => $this->faker->company(),
            'code' => strtoupper($this->faker->unique()->regexify('[A-Z]{3}[0-9]{4}')),
            'type' => $this->faker->randomElement(['retail', 'wholesale', 'distributor', 'corporate']),
            'contact_person' => $this->faker->name(),
            'email' => $this->faker->unique()->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'whatsapp_no' => $this->faker->phoneNumber(),
            'gstin' => strtoupper($this->faker->regexify('[A-Z0-9]{15}')),
            'pan' => strtoupper($this->faker->regexify('[A-Z0-9]{10}')),
            'address_line1' => $this->faker->streetAddress(),
            'address_line2' => $this->faker->optional()->buildingNumber(),
            'city' => $this->faker->city(),
            'state' => $this->faker->state(),
            'state_code' => strtoupper($this->faker->regexify('[A-Z]{2}')),
            'pincode' => $this->faker->postcode(),
            'country' => 'India',
            'credit_limit' => $this->faker->randomFloat(2, 10000, 100000),
            'outstanding_balance' => 0,
            'payment_terms_days' => $this->faker->numberBetween(0, 90),
            'notes' => $this->faker->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => false,
            ];
        });
    }
}
