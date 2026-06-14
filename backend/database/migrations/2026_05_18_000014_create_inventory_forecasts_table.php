<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('panel_type_id')->nullable()->constrained('panel_types')->onDelete('set null');
            $table->date('forecast_date');
            $table->date('forecast_for_date');
            $table->integer('predicted_quantity');
            $table->string('forecast_type'); // 'conservative', 'moderate', 'aggressive'
            $table->decimal('confidence_score', 5, 2); // 0-100
            $table->string('method'); // 'moving_average', 'exponential_smoothing', 'linear_regression'
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'forecast_date']);
            $table->index(['panel_type_id', 'forecast_for_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_forecasts');
    }
};
