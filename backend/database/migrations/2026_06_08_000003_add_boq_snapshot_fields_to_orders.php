<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('project_name', 255)->nullable()->after('customer_id');
            $table->string('project_location', 255)->nullable()->after('project_name');
            $table->enum('quality_grade', ['High', 'Medium', 'Standard'])->default('High')->after('project_location');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('subtotal');
            $table->decimal('taxable_amount', 12, 2)->default(0)->after('discount_amount');
            $table->decimal('cgst_amount', 12, 2)->default(0)->after('taxable_amount');
            $table->decimal('sgst_amount', 12, 2)->default(0)->after('cgst_amount');
            $table->decimal('igst_amount', 12, 2)->default(0)->after('sgst_amount');
            $table->boolean('is_inter_state')->default(false)->after('igst_amount');
            $table->boolean('transport_fixed')->default(false)->after('is_inter_state');
            $table->decimal('transport_amount', 12, 2)->default(0)->after('transport_fixed');
            $table->decimal('advance_pct', 5, 2)->default(50)->after('transport_amount');
            $table->decimal('advance_amount', 12, 2)->default(0)->after('advance_pct');
            $table->decimal('balance_amount', 12, 2)->default(0)->after('advance_amount');
            $table->decimal('total_sqm', 10, 4)->default(0)->after('balance_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'project_name', 'project_location', 'quality_grade',
                'discount_amount', 'taxable_amount',
                'cgst_amount', 'sgst_amount', 'igst_amount', 'is_inter_state',
                'transport_fixed', 'transport_amount',
                'advance_pct', 'advance_amount', 'balance_amount', 'total_sqm',
            ]);
        });
    }
};
