<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — per-company constants for the BOM / material-requirement engine.
 * One row per company (auto-created with sensible defaults). softDeletes
 * required (BaseModel forces SoftDeletes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->decimal('steel_density', 6, 3)->default(7.850)->comment('g/cm3 for coil kg = area*thk*density');
            $table->decimal('iso_polyol_ratio', 6, 3)->default(1.100)->comment('iso per 1 part polyol by weight');
            $table->decimal('foam_overpack_pct', 5, 2)->default(5.00);
            $table->decimal('wastage_coil_pct', 5, 2)->default(3.00);
            $table->decimal('wastage_chemical_pct', 5, 2)->default(5.00);
            $table->decimal('wastage_consumable_pct', 5, 2)->default(2.00);
            $table->decimal('film_per_sqm', 6, 3)->default(1.000)->comment('sqm film per sqm panel (if guard film)');
            $table->decimal('tape_per_panel_m', 6, 2)->default(0.00)->comment('metres tape per panel');
            $table->timestamps();
            $table->softDeletes();
            $table->unique('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_settings');
    }
};
