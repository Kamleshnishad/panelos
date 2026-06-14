<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        $company = Company::first() ?? Company::factory()->create();
        $role = Role::where('company_id', $company->id)->first()
            ?? Role::factory()->for($company)->create();

        return [
            'company_id' => $company->id,
            'role_id' => $role->id,
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'whatsapp_no' => $this->faker->phoneNumber(),
            'password' => Hash::make('password123'),
            'is_super_admin' => false,
            'is_company_admin' => false,
            'is_active' => true,
            'email_verified_at' => now(),
            'last_login_at' => null,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_super_admin' => true,
                'is_company_admin' => true,
            ];
        });
    }

    public function companyAdmin(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_company_admin' => true,
            ];
        });
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
