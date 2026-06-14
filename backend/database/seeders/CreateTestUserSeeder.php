<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CreateTestUserSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('subdomain', 'demo')->first();

        if ($company) {
            User::updateOrCreate(
                ['email' => 'admin@demo.local'],
                [
                    'company_id' => $company->id,
                    'name' => 'Demo Admin',
                    'password' => Hash::make('password123'),
                    'is_super_admin' => true,
                    'is_company_admin' => true,
                    'is_active' => true,
                ]
            );

            $this->command->info('Test admin user created!');
            $this->command->info('Email: admin@demo.local');
            $this->command->info('Password: password123');
        }
    }
}
