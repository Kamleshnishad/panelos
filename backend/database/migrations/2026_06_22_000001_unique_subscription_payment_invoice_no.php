<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CONC-H3 — safety net against duplicate platform subscription-invoice numbers.
 *
 * DEFENSIVE: adds the unique index ONLY if no duplicate non-null invoice_no
 * already exists. If duplicates are present it skips + logs them (so a deploy is
 * never broken by pre-existing data) and leaves a manual-cleanup breadcrumb.
 * Multiple NULLs are allowed by MySQL unique indexes, so record=false rows are fine.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('subscription_payments') || !Schema::hasColumn('subscription_payments', 'invoice_no')) {
            return;
        }
        if ($this->hasIndex('subscription_payments', 'subscription_payments_invoice_no_unique')) {
            return; // already applied
        }

        $dupes = DB::table('subscription_payments')
            ->select('invoice_no', DB::raw('COUNT(*) as c'))
            ->whereNotNull('invoice_no')
            ->groupBy('invoice_no')
            ->having('c', '>', 1)
            ->pluck('invoice_no')
            ->all();

        if (!empty($dupes)) {
            Log::warning('[migration] subscription_payments.invoice_no unique index SKIPPED — duplicates exist; clean these first: ' . implode(', ', $dupes));
            return;
        }

        Schema::table('subscription_payments', fn (Blueprint $t) => $t->unique('invoice_no', 'subscription_payments_invoice_no_unique'));
    }

    public function down(): void
    {
        if ($this->hasIndex('subscription_payments', 'subscription_payments_invoice_no_unique')) {
            Schema::table('subscription_payments', fn (Blueprint $t) => $t->dropUnique('subscription_payments_invoice_no_unique'));
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
