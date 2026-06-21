<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Heals schema drift on `panel_types`: some deployed databases were created
 * from an older create-migration and are missing columns the app writes
 * (category, hsn_code, thickness, …), causing "Unknown column 'category'".
 *
 * Idempotent — each column is added only if missing, so it's a no-op on
 * databases that already have them (e.g. local/dev). Defaults are supplied so
 * adding NOT NULL columns to tables with existing rows never fails.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('panel_types')) return;

        Schema::table('panel_types', function (Blueprint $table) {
            if (!Schema::hasColumn('panel_types', 'category')) {
                $table->enum('category', ['roof', 'wall', 'ceiling', 'cold_room'])->default('wall');
            }
            if (!Schema::hasColumn('panel_types', 'hsn_code')) {
                $table->string('hsn_code', 20)->default('39259010');
            }
            if (!Schema::hasColumn('panel_types', 'available_thicknesses')) {
                $table->json('available_thicknesses')->nullable();
            }
            if (!Schema::hasColumn('panel_types', 'description')) {
                $table->text('description')->nullable();
            }
            if (!Schema::hasColumn('panel_types', 'image')) {
                $table->string('image')->nullable();
            }
            if (!Schema::hasColumn('panel_types', 'thickness')) {
                $table->decimal('thickness', 8, 2)->default(50);
            }
            if (!Schema::hasColumn('panel_types', 'width')) {
                $table->decimal('width', 10, 2)->default(1000);
            }
            if (!Schema::hasColumn('panel_types', 'length')) {
                $table->integer('length')->default(2000);
            }
            if (!Schema::hasColumn('panel_types', 'thermal_resistance')) {
                $table->decimal('thermal_resistance', 8, 2)->default(2.5);
            }
            if (!Schema::hasColumn('panel_types', 'base_price')) {
                $table->decimal('base_price', 12, 2)->default(0);
            }
            if (!Schema::hasColumn('panel_types', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });
    }

    public function down(): void
    {
        // No-op: we never drop columns that the app depends on.
    }
};
