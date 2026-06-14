<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_accessories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->onDelete('cascade');
            $table->foreignId('accessory_id')->constrained('accessories')->onDelete('restrict');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['quotation_id', 'accessory_id']);
            $table->index(['quotation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_accessories');
    }
};
