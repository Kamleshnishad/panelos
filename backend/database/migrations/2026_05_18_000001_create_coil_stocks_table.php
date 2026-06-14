<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coil_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->unsignedBigInteger('coil_id')->nullable();
            $table->unsignedBigInteger('panel_type_id')->nullable();
            $table->foreign('panel_type_id')->references('id')->on('panel_types')->onDelete('set null');
            $table->decimal('quantity_in_stock', 12, 2)->default(0);
            $table->decimal('reorder_level', 12, 2)->default(0);
            $table->timestamp('last_stock_in')->nullable();
            $table->timestamp('last_stock_out')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'coil_id']);
            $table->index(['company_id', 'quantity_in_stock']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coil_stocks');
    }
};
