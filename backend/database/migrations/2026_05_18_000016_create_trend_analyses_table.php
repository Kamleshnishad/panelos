<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trend_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('panel_type_id')->nullable()->constrained('panel_types')->onDelete('set null');
            $table->date('analysis_date');
            $table->integer('period_days'); // 7, 30, 90, 365
            $table->decimal('growth_rate', 8, 4); // percentage
            $table->decimal('volatility', 8, 4); // standard deviation
            $table->integer('peak_sales');
            $table->integer('low_sales');
            $table->decimal('average_sales', 10, 2);
            $table->string('trend_direction'); // 'upward', 'downward', 'stable'
            $table->integer('seasonal_pattern'); // 1-12 for months with peaks
            $table->decimal('year_over_year_change', 8, 4)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'analysis_date']);
            $table->index(['panel_type_id', 'period_days']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trend_analyses');
    }
};
