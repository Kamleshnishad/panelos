<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Post-audit (2026-06-17) schema fixes:
 *  - payment_transactions: enum can now hold 'stripe', 'razorpay', 'write_off';
 *    new `status` column; new `transaction_id` column with composite-unique
 *    index so Stripe/Razorpay webhooks can be idempotent (retries dedupe).
 *  - Cross-tenant uniqueness: invoice_no / dispatch_no / customers.code were
 *    GLOBALLY unique, blocking second tenants from reusing the same prefix.
 *    Switched to composite (company_id, *_no).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // ── payment_transactions: enum + status + transaction_id ───────────
        // SQLite (used in test suite) has no native ENUM and no MODIFY COLUMN.
        // Skip the enum change there; SQLite happily stores any string in the
        // column anyway so tests still pass.
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(
                "ALTER TABLE payment_transactions MODIFY COLUMN payment_method " .
                "ENUM('bank_transfer','cash','cheque','upi','other','stripe','razorpay','write_off') " .
                "NOT NULL DEFAULT 'bank_transfer'"
            );
        }

        Schema::table('payment_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_transactions', 'status')) {
                $table->enum('status', ['pending', 'completed', 'failed', 'refunded'])
                    ->default('completed')
                    ->after('payment_method');
            }
            if (!Schema::hasColumn('payment_transactions', 'transaction_id')) {
                $table->string('transaction_id', 191)->nullable()->after('reference_no');
                // (company_id, transaction_id) so two tenants can have a same-named
                // gateway transaction without colliding, but within a tenant the
                // gateway-side id is dedup-keyed (webhook retries → no double credit).
                $table->unique(['company_id', 'transaction_id'], 'pt_company_txn_unique');
            }
        });

        // ── invoices.invoice_no: drop global unique → (company_id, invoice_no)
        $this->swapToCompositeUnique('invoices', 'invoice_no');

        // ── dispatches.dispatch_no: drop global unique → composite
        $this->swapToCompositeUnique('dispatches', 'dispatch_no');

        // ── customers.code: drop global unique → composite
        $this->swapToCompositeUnique('customers', 'code');
    }

    public function down(): void
    {
        // Best-effort rollback. Schema downgrade is rarely run in prod; this
        // exists so dev environments can rebuild cleanly.
        Schema::table('payment_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('payment_transactions', 'transaction_id')) {
                try { $table->dropUnique('pt_company_txn_unique'); } catch (\Throwable) {}
                $table->dropColumn('transaction_id');
            }
            if (Schema::hasColumn('payment_transactions', 'status')) {
                $table->dropColumn('status');
            }
        });

        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(
                "ALTER TABLE payment_transactions MODIFY COLUMN payment_method " .
                "ENUM('bank_transfer','cash','cheque','upi','other') NOT NULL DEFAULT 'bank_transfer'"
            );
        }

        $this->revertToGlobalUnique('invoices', 'invoice_no');
        $this->revertToGlobalUnique('dispatches', 'dispatch_no');
        $this->revertToGlobalUnique('customers', 'code');
    }

    private function swapToCompositeUnique(string $table, string $column): void
    {
        Schema::table($table, function (Blueprint $t) use ($table, $column) {
            // Default Laravel-generated unique index name is "{table}_{column}_unique".
            try { $t->dropUnique("{$table}_{$column}_unique"); } catch (\Throwable) {}
            $t->unique(['company_id', $column], "{$table}_company_{$column}_unique");
        });
    }

    private function revertToGlobalUnique(string $table, string $column): void
    {
        Schema::table($table, function (Blueprint $t) use ($table, $column) {
            try { $t->dropUnique("{$table}_company_{$column}_unique"); } catch (\Throwable) {}
            try { $t->unique($column); } catch (\Throwable) {}
        });
    }
};
