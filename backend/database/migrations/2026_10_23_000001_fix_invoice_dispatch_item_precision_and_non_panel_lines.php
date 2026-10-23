<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two revenue-accuracy fixes on the dispatch → invoice path.
 *
 * 1. `quantity` was an INTEGER on both dispatch_items and invoice_items, but it
 *    carries square metres (e.g. 312.93), so every fractional order silently
 *    rounded up at dispatch and over-billed the customer. Widened to
 *    decimal(12,4), matching orders.total_sqm.
 *
 * 2. invoice_items could only hold panel lines (panel_type_id NOT NULL), so
 *    accessories, doors and installation charges had no way onto an invoice and
 *    that revenue was never billed. panel_type_id is now nullable and a
 *    description column carries the label for non-panel lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isMysql = \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'mysql';

        Schema::table('dispatch_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 4)->change();
        });

        // MySQL refuses to redefine a column that an FK points at, so the
        // constraint comes off first and goes back on after.
        if ($isMysql) {
            Schema::table('invoice_items', function (Blueprint $table) {
                try { $table->dropForeign('invoice_items_panel_type_id_foreign'); } catch (\Throwable) {}
            });
        }

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 4)->change();
            $table->unsignedBigInteger('panel_type_id')->nullable()->change();
            if (!Schema::hasColumn('invoice_items', 'description')) {
                $table->string('description', 255)->nullable()->after('panel_type_id');
            }
        });

        if ($isMysql) {
            Schema::table('invoice_items', function (Blueprint $table) {
                try {
                    $table->foreign('panel_type_id', 'invoice_items_panel_type_id_foreign')
                        ->references('id')->on('panel_types')->nullOnDelete();
                } catch (\Throwable) {}
            });
        }
    }

    public function down(): void
    {
        Schema::table('dispatch_items', function (Blueprint $table) {
            $table->integer('quantity')->change();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->integer('quantity')->change();
            if (Schema::hasColumn('invoice_items', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
