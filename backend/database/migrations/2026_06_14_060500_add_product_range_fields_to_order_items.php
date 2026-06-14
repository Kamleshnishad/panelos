<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror the quotation_items product-range expansion onto order_items so the
 * full spec (application / core type / fixing system / RAL / bottom surface)
 * survives into the production + dispatch lifecycle, not just the quote.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE order_items MODIFY COLUMN density_type ENUM('PUF','PIR','Rockwool','EPS','Glasswool') NOT NULL DEFAULT 'PUF'");

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('application', 40)->nullable()->after('panel_type_id');
            $table->string('fixing_system', 40)->nullable()->after('cello_tap');
            $table->string('top_color_ral', 20)->nullable()->after('top_color');
            $table->string('bottom_color_ral', 20)->nullable()->after('bottom_color');
            // order_items never had a bottom_surface column; add it for parity.
            $table->enum('bottom_surface', ['RIBBED', 'PLAIN'])->default('PLAIN')->after('bottom_color_ral');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['application', 'fixing_system', 'top_color_ral', 'bottom_color_ral', 'bottom_surface']);
        });
        DB::statement("ALTER TABLE order_items MODIFY COLUMN density_type ENUM('PUF','PIR') NOT NULL DEFAULT 'PUF'");
    }
};
