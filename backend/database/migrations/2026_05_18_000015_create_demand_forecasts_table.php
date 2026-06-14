<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('panel_type_id')->nullable()->constrained('panel_types')->onDelete('set null');
            $table->date('forecast_date');
            $table->integer('forecast_period_days');
            $table->integer('predicted_demand');
            $table->integer('current_stock');
            $table->integer('reorder_quantity');
            $table->date('recommended_order_date');
            $table->decimal('seasonal_factor', 5, 2); // 0.5 to 2.0
            $table->decimal('trend_strength', 5, 2); // -1 to 1
            $table->string('risk_level'); // 'low', 'medium', 'high'
            $table->timestamps();

            $table->index(['company_id', 'forecast_date']);
            $table->index(['panel_type_id', 'forecast_date']);
            $table->index('recommended_order_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_forecasts');
    }
};
