<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Panel specification snapshot (copied from quotation_items at order time)
            $table->unsignedSmallInteger('thickness')->nullable()->after('panel_type_id');
            $table->enum('density_type', ['PUF', 'PIR'])->default('PUF')->after('thickness');
            $table->decimal('density_kgm3', 5, 1)->default(40)->after('density_type');
            $table->string('top_skin_material', 30)->default('PPGI')->after('density_kgm3');
            $table->decimal('top_skin_thickness', 4, 2)->default(0.40)->after('top_skin_material');
            $table->string('top_color', 50)->default('Off White')->after('top_skin_thickness');
            $table->enum('top_surface', ['RIBBED', 'PLAIN'])->default('PLAIN')->after('top_color');
            $table->string('bottom_skin_material', 30)->default('PPGI')->after('top_surface');
            $table->decimal('bottom_skin_thickness', 4, 2)->default(0.40)->after('bottom_skin_material');
            $table->string('bottom_color', 50)->default('Off White')->after('bottom_skin_thickness');
            $table->boolean('guard_film')->default(false)->after('bottom_color');
            $table->boolean('cello_tap')->default(false)->after('guard_film');
            $table->string('hsn_code', 20)->default('39259010')->after('cello_tap');
            // Totals
            $table->decimal('total_sqm', 10, 4)->default(0)->after('hsn_code');
            $table->decimal('rate_per_sqm', 10, 2)->default(0)->after('total_sqm');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('rate_per_sqm');
            // Re-add timestamps (currently $timestamps = false in model, but useful for audit)
            $table->timestamps();
        });

        // Create order_item_sizes to snapshot each size row from quotation_item_sizes
        Schema::create('order_item_sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->onDelete('cascade');
            $table->unsignedInteger('length_mm');
            $table->unsignedSmallInteger('width_mm')->default(1000);
            $table->unsignedInteger('nos');
            $table->decimal('sqm', 10, 4)->default(0);
            $table->decimal('rate_per_sqm', 10, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_sizes');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn([
                'thickness', 'density_type', 'density_kgm3',
                'top_skin_material', 'top_skin_thickness', 'top_color', 'top_surface',
                'bottom_skin_material', 'bottom_skin_thickness', 'bottom_color',
                'guard_film', 'cello_tap', 'hsn_code',
                'total_sqm', 'rate_per_sqm', 'sort_order',
                'created_at', 'updated_at',
            ]);
        });
    }
};
