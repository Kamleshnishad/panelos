<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->string('project_name', 255)->nullable()->after('customer_id');
            $table->string('project_location', 255)->nullable()->after('project_name');
            $table->enum('quality_grade', ['High', 'Medium', 'Standard'])->default('High')->after('project_location');
            $table->unsignedSmallInteger('validity_days')->default(10)->after('quality_grade');
            $table->decimal('discount_pct', 5, 2)->default(0)->after('validity_days');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_pct');
            $table->decimal('taxable_amount', 12, 2)->default(0)->after('discount_amount');
            $table->decimal('cgst_amount', 12, 2)->default(0)->after('taxable_amount');
            $table->decimal('sgst_amount', 12, 2)->default(0)->after('cgst_amount');
            $table->decimal('igst_amount', 12, 2)->default(0)->after('sgst_amount');
            $table->boolean('is_inter_state')->default(false)->after('igst_amount');
            $table->boolean('transport_fixed')->default(false)->after('is_inter_state');
            $table->decimal('transport_amount', 12, 2)->default(0)->after('transport_fixed');
            $table->decimal('round_off', 8, 2)->default(0)->after('transport_amount');
            $table->decimal('advance_pct', 5, 2)->default(50)->after('round_off');
            $table->decimal('advance_amount', 12, 2)->default(0)->after('advance_pct');
            $table->decimal('balance_amount', 12, 2)->default(0)->after('advance_amount');
            $table->decimal('panel_subtotal', 12, 2)->default(0)->after('balance_amount');
            $table->decimal('accessory_subtotal', 12, 2)->default(0)->after('panel_subtotal');
            $table->decimal('installation_amount', 12, 2)->default(0)->after('accessory_subtotal');
            $table->decimal('total_sqm', 10, 4)->default(0)->after('installation_amount');
            $table->unsignedBigInteger('parent_quotation_id')->nullable()->after('total_sqm');
            $table->unsignedTinyInteger('revision_number')->default(1)->after('parent_quotation_id');
            $table->string('quotation_prefix', 10)->default('SCP')->after('revision_number');
            $table->text('terms_and_conditions')->nullable()->after('quotation_prefix');
            $table->timestamp('rejected_at')->nullable()->after('accepted_at');
            $table->timestamp('expired_at')->nullable()->after('rejected_at');

            $table->foreign('parent_quotation_id')->references('id')->on('quotations')->onDelete('set null');
        });

        // Add REVISED to status enum
        DB::statement("ALTER TABLE quotations MODIFY COLUMN status ENUM('draft','sent','accepted','rejected','revised','expired') DEFAULT 'draft'");

        // Full rebuild of quotation_items with all 21 BOQ fields
        Schema::drop('quotation_items');
        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->onDelete('cascade');
            $table->foreignId('panel_type_id')->constrained('panel_types')->onDelete('restrict');
            // Skin & surface spec
            $table->unsignedSmallInteger('thickness')->nullable()->comment('mm');
            $table->enum('density_type', ['PUF', 'PIR'])->default('PUF');
            $table->decimal('density_kgm3', 5, 1)->default(40);
            $table->string('top_skin_material', 30)->default('PPGI');
            $table->decimal('top_skin_thickness', 4, 2)->default(0.40)->comment('mm');
            $table->string('top_color', 50)->default('Off White');
            $table->enum('top_surface', ['RIBBED', 'PLAIN'])->default('PLAIN');
            $table->string('bottom_skin_material', 30)->default('PPGI');
            $table->decimal('bottom_skin_thickness', 4, 2)->default(0.40);
            $table->string('bottom_color', 50)->default('Off White');
            $table->enum('bottom_surface', ['RIBBED', 'PLAIN'])->default('PLAIN');
            $table->boolean('guard_film')->default(false);
            $table->boolean('cello_tap')->default(false);
            $table->string('hsn_code', 20)->default('39259010');
            // Totals for this row
            $table->decimal('total_sqm', 10, 4)->default(0);
            $table->decimal('rate_per_sqm', 10, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            // Legacy compat
            $table->decimal('quantity', 10, 2)->default(0)->comment('SQM total, kept for compat');
            $table->decimal('unit_price', 12, 2)->default(0)->comment('rate_per_sqm alias');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index('quotation_id');
        });

        // Size breakdown rows (the sub-table per panel row)
        Schema::create('quotation_item_sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_item_id')->constrained('quotation_items')->onDelete('cascade');
            $table->unsignedInteger('length_mm');
            $table->unsignedSmallInteger('width_mm')->default(1000);
            $table->unsignedInteger('nos');
            $table->decimal('sqm', 10, 4)->storedAs('(length_mm / 1000) * (width_mm / 1000) * nos');
            $table->decimal('rate_per_sqm', 10, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index('quotation_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_item_sizes');

        Schema::table('quotation_items', function (Blueprint $table) {
            // Restore minimal original structure
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn([
                'project_name', 'project_location', 'quality_grade', 'validity_days',
                'discount_pct', 'discount_amount', 'taxable_amount',
                'cgst_amount', 'sgst_amount', 'igst_amount', 'is_inter_state',
                'transport_fixed', 'transport_amount', 'round_off',
                'advance_pct', 'advance_amount', 'balance_amount',
                'panel_subtotal', 'accessory_subtotal', 'installation_amount',
                'total_sqm', 'parent_quotation_id', 'revision_number',
                'quotation_prefix', 'terms_and_conditions', 'rejected_at', 'expired_at',
            ]);
        });
    }
};
