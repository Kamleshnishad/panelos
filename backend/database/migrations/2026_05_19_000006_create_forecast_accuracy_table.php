<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_accuracies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('panel_type_id');
            $table->unsignedBigInteger('demand_forecast_id')->nullable();
            $table->decimal('predicted_quantity', 12, 2);
            $table->decimal('actual_quantity', 12, 2);
            $table->decimal('mape', 8, 2)->comment('Mean Absolute Percentage Error');
            $table->decimal('accuracy_score', 8, 2)->comment('100 - MAPE');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('panel_type_id')->references('id')->on('panel_types')->onDelete('cascade');
            $table->index(['company_id', 'panel_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_accuracies');
    }
};
