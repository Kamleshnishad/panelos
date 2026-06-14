<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * L1: guarantee per-company uniqueness of quotation_no / order_no so a number
     *     generation race can never persist duplicates (it errors loudly instead).
     * L4: drop the orphan, superseded `quotation_panel_items` table (no model, 0 rows).
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->unique(['company_id', 'quotation_no'], 'quotations_company_no_unique');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unique(['company_id', 'order_no'], 'orders_company_no_unique');
        });

        Schema::dropIfExists('quotation_panel_items');
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropUnique('quotations_company_no_unique');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_company_no_unique');
        });
        // Note: the dropped orphan table is not recreated (it was dead schema).
    }
};
