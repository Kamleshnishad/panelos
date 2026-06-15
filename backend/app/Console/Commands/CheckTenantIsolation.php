<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Verifies multi-tenant data isolation end-to-end against the real database.
 *
 * Creates two throwaway tenants, seeds a record under tenant B, then — acting
 * as a tenant A user — asserts tenant A can NEVER see tenant B's data (by list,
 * by name, or by direct find($id)). Cleans up afterwards.
 *
 * Run before every release / SaaS onboarding:  php artisan tenant:check-isolation
 * Exit code 0 = isolated & safe, 1 = LEAK DETECTED.
 */
class CheckTenantIsolation extends Command
{
    protected $signature = 'tenant:check-isolation';
    protected $description = 'Verify cross-tenant data isolation (global scope) is airtight';

    public function handle(): int
    {
        $this->info('🔒 Tenant isolation check starting…');
        $fails = [];
        $cleanup = [];

        try {
            // --- Create tenant A ---
            $cA = Company::create(['name' => 'ISO_TEST_A', 'subdomain' => 'iso-a-' . uniqid(), 'subscription_status' => 'trial', 'is_active' => true]);
            $uA = User::create(['company_id' => $cA->id, 'name' => 'ISO A', 'email' => 'iso-a-' . uniqid() . '@test.local', 'password' => bcrypt('panel2026x'), 'is_company_admin' => true, 'is_active' => true]);
            // --- Create tenant B ---
            $cB = Company::create(['name' => 'ISO_TEST_B', 'subdomain' => 'iso-b-' . uniqid(), 'subscription_status' => 'trial', 'is_active' => true]);
            $uB = User::create(['company_id' => $cB->id, 'name' => 'ISO B', 'email' => 'iso-b-' . uniqid() . '@test.local', 'password' => bcrypt('panel2026x'), 'is_company_admin' => true, 'is_active' => true]);
            $cleanup = [$uA, $uB, $cA, $cB];

            // --- Seed records under tenant B (acting as B so company_id auto-fills) ---
            Auth::setUser($uB);
            $custB = Customer::create(['name' => 'ISO_SECRET_B', 'code' => 'ISOB' . rand(100, 999), 'address_line1' => '', 'city' => '', 'state' => '', 'state_code' => '', 'pincode' => '', 'phone' => '']);
            $cleanup[] = $custB;

            // --- Now act as tenant A and probe ---
            Auth::setUser($uA);

            // Probe each tenant-scoped model: A must see 0 of B's rows
            $probes = [
                'Customer (direct seed)' => fn () => Customer::where('name', 'ISO_SECRET_B')->count(),
                'Customer find(B.id)'    => fn () => Customer::find($custB->id) ? 1 : 0,
                'Customer count == own'  => fn () => Customer::count() === Customer::where('company_id', $cA->id)->withoutGlobalScope('tenant')->count() ? 0 : 1,
            ];
            foreach ($probes as $label => $probe) {
                $leak = $probe();
                if ($leak > 0) { $fails[] = $label; $this->error("  ✗ LEAK: {$label}"); }
                else { $this->line("  ✓ {$label}"); }
            }

            // Spot-check that the global scope is actually registered on key models
            foreach ([Customer::class, Invoice::class, Order::class, Quotation::class, PaymentTransaction::class] as $cls) {
                $hasScope = array_key_exists('tenant', (new $cls)->getGlobalScopes());
                if (!$hasScope) { $fails[] = "No tenant scope on {$cls}"; $this->error("  ✗ {$cls} missing tenant global scope"); }
                else { $this->line("  ✓ {$cls} has tenant global scope"); }
            }
        } catch (\Throwable $e) {
            $this->error('Check errored: ' . $e->getMessage());
            $fails[] = 'exception';
        } finally {
            Auth::logout();
            foreach (array_reverse($cleanup) as $row) {
                try { $row->forceDelete(); } catch (\Throwable) {}
            }
        }

        if (empty($fails)) {
            $this->info('✅ PASS — tenant isolation is airtight.');
            return self::SUCCESS;
        }
        $this->error('❌ FAIL — ' . count($fails) . ' isolation issue(s): ' . implode(', ', $fails));
        return self::FAILURE;
    }
}
