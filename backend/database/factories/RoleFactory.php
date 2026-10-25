<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            // Role names are unique per company, and a test often makes two (the user factory makes one of its own): a random job title alone collides now and then (PB-070).
            'name' => $this->faker->jobTitle() . ' ' . strtoupper(\Illuminate\Support\Str::random(5)),
            'guard_name' => 'web',
            'permissions' => ['*.view'],
            'description' => $this->faker->sentence(),
            'is_system_role' => false,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'Super Admin',
                'permissions' => ['*'],
                'is_system_role' => true,
            ];
        });
    }

    public function viewer(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'Viewer',
                'permissions' => ['*.view'],
                'is_system_role' => true,
            ];
        });
    }
}
