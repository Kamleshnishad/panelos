<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_panel_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('quotation_id')->constrained('quotations')->onDelete('cascade');
            $table->foreignId('panel_type_id')->constrained('panel_types')->onDelete('restrict');
            $table->integer('sequence')->default(1);
            $table->decimal('length_mm', 10, 2);
            $table->decimal('width_mm', 10, 2);
            $table->decimal('thickness_mm', 5, 2);
            $table->decimal('density', 5, 2);
            $table->string('core_material');
            $table->string('top_skin')->nullable();
            $table->string('bottom_skin')->nullable();
            $table->enum('top_surface', ['ribbed', 'plain'])->default('plain');
            $table->enum('bottom_surface', ['ribbed', 'plain'])->default('plain');
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->decimal('sqm', 10, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['quotation_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_panel_items');
    }
};
