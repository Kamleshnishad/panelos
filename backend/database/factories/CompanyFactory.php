<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'subdomain' => $this->faker->unique()->slug(),
            'email' => $this->faker->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'gstin' => $this->faker->numerify('##AABCT####A##'),
            'pan' => $this->faker->bothify('?????#####?'),
            'address_line1' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'state' => $this->faker->stateAbbr(),
            'state_code' => $this->faker->bothify('??'),
            'pincode' => $this->faker->postcode(),
            'bank_name' => $this->faker->company() . ' Bank',
            'bank_account_no' => $this->faker->bankAccountNumber(),
            'bank_ifsc' => $this->faker->bothify('????0######'),
            'bank_branch' => $this->faker->city(),
            'authorized_signatory' => $this->faker->name(),
            'signatory_phone' => $this->faker->phoneNumber(),
            'primary_color' => '#1a237e',
            'secondary_color' => '#f57f17',
            'quotation_prefix' => 'SCP',
            'invoice_prefix' => 'INV',
            'order_prefix' => 'ORD',
            'challan_prefix' => 'CH',
            'financial_year_start' => 4,
            'settings' => [
                'currency' => 'INR',
                'timezone' => 'Asia/Kolkata',
            ],
            'is_active' => true,
            'subscription_plan' => 'pro',
            'subscription_status' => 'active',
        ];
    }
}
