<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('panel_type_id')->nullable()->constrained('panel_types')->onDelete('set null');
            $table->date('metric_date');
            $table->integer('quantity_sold')->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->decimal('average_price', 12, 2)->default(0);
            $table->integer('invoice_count')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'metric_date']);
            $table->index(['panel_type_id', 'metric_date']);
            $table->unique(['company_id', 'panel_type_id', 'metric_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_metrics');
    }
};
