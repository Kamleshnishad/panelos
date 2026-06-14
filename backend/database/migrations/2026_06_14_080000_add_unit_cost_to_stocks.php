<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — add unit_cost to coil & chemical stock for valuation
 * (stock value currently returns 0 because there is no cost basis).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coil_stocks', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->default(0)->after('reorder_level')->comment('cost per kg');
        });
        Schema::table('chemical_stocks', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->default(0)->after('reorder_level')->comment('cost per unit');
        });
    }

    public function down(): void
    {
        Schema::table('coil_stocks', fn (Blueprint $t) => $t->dropColumn('unit_cost'));
        Schema::table('chemical_stocks', fn (Blueprint $t) => $t->dropColumn('unit_cost'));
    }
};
