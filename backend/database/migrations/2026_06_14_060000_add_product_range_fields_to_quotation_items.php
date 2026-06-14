<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen quotation_items so the system can quote the customer's FULL catalogue,
 * not just PUF/PIR wall-or-roof panels:
 *   - application      → Wall / Roof / Cold Room / Partition / Clean Room / Ceiling / PEB Shade / Architectural
 *   - density_type     → now also Rockwool / EPS / Glasswool (was only PUF/PIR)
 *   - fixing_system    → Cam-Lock / Secret-Fix / Standing-Seam / Lap-Joint / Visible-Fix
 *   - *_color_ral      → RAL code beside the colour name (procurement key for coil)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Expand the core/insulation type beyond PUF/PIR.
        DB::statement("ALTER TABLE quotation_items MODIFY COLUMN density_type ENUM('PUF','PIR','Rockwool','EPS','Glasswool') NOT NULL DEFAULT 'PUF'");

        Schema::table('quotation_items', function (Blueprint $table) {
            $table->string('application', 40)->nullable()->after('panel_type_id')
                  ->comment('Wall / Roof / Cold Room / Partition / Clean Room / Ceiling / PEB Shade / Architectural');
            $table->string('fixing_system', 40)->nullable()->after('cello_tap')
                  ->comment('Cam-Lock / Secret-Fix / Standing-Seam / Lap-Joint / Visible-Fix');
            $table->string('top_color_ral', 20)->nullable()->after('top_color');
            $table->string('bottom_color_ral', 20)->nullable()->after('bottom_color');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropColumn(['application', 'fixing_system', 'top_color_ral', 'bottom_color_ral']);
        });
        DB::statement("ALTER TABLE quotation_items MODIFY COLUMN density_type ENUM('PUF','PIR') NOT NULL DEFAULT 'PUF'");
    }
};
