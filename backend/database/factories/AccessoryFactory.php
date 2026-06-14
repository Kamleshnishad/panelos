<?php

namespace Database\Factories;

use App\Models\Accessory;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccessoryFactory extends Factory
{
    protected $model = Accessory::class;

    public function definition(): array
    {
        $company = Company::first() ?? Company::factory()->create();

        return [
            'company_id' => $company->id,
            'name' => $this->faker->word() . ' Accessory',
            'code' => strtoupper($this->faker->unique()->regexify('[A-Z]{3}[0-9]{3}')),
            'description' => $this->faker->sentence(),
            'unit_price' => $this->faker->randomFloat(2, 100, 5000),
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
