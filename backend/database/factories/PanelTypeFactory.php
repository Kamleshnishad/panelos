<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PanelType;
use Illuminate\Database\Eloquent\Factories\Factory;

class PanelTypeFactory extends Factory
{
    protected $model = PanelType::class;

    public function definition(): array
    {
        $company = Company::first() ?? Company::factory()->create();

        return [
            'company_id' => $company->id,
            'name' => $this->faker->word() . ' Panel',
            'code' => strtoupper($this->faker->unique()->regexify('[A-Z]{2}[0-9]{3}')),
            'description' => $this->faker->sentence(),
            'thickness' => $this->faker->randomFloat(2, 25, 150),
            'width' => $this->faker->randomFloat(2, 1000, 2000),
            'thermal_resistance' => $this->faker->randomFloat(2, 0.5, 5),
            'base_price' => $this->faker->randomFloat(2, 500, 5000),
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
