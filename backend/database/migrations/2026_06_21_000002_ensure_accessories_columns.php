<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Heals schema drift on `accessories` (deployed DB was missing unit/hsn_code/
 * rate). Idempotent — adds each only if missing, with defaults so it's safe on
 * tables that already have rows and a no-op where the columns already exist.
 *
 * (A full local-vs-deployed schema diff confirmed accessories was the only
 * table with genuinely missing columns; all other differences were benign
 * MySQL-8-vs-MariaDB display widths, e.g. bigint(20) vs bigint.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('accessories')) return;

        Schema::table('accessories', function (Blueprint $table) {
            if (!Schema::hasColumn('accessories', 'unit')) {
                $table->string('unit', 20)->default('NOS');
            }
            if (!Schema::hasColumn('accessories', 'hsn_code')) {
                $table->string('hsn_code', 20)->default('73089090');
            }
            if (!Schema::hasColumn('accessories', 'rate')) {
                $table->decimal('rate', 12, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        // No-op: never drop columns the app depends on.
    }
};
