<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('low_stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->enum('item_type', ['coil', 'chemical']);
            $table->unsignedBigInteger('item_id');
            $table->decimal('current_quantity', 12, 2);
            $table->decimal('reorder_level', 12, 2);
            $table->enum('alert_type', ['low_stock', 'expiring_soon', 'out_of_stock'])->default('low_stock');
            $table->enum('status', ['active', 'resolved'])->default('active');
            $table->timestamp('alert_sent_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['item_type', 'item_id']);
            $table->unique(['company_id', 'item_type', 'item_id', 'alert_type', 'status'], 'low_stk_alert_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('low_stock_alerts');
    }
};
