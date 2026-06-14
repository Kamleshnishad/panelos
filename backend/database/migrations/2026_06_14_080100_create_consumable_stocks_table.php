<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — consumable stock (oil/release agent, protective film, sealant tape,
 * packaging, etc.). Self-describing rows (no master table), same pattern as
 * chemical_stocks. softDeletes required (BaseModel forces SoftDeletes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumable_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('name', 120);
            $table->string('category', 50)->nullable()->comment('oil/film/tape/packaging/other');
            $table->string('unit', 20)->default('nos')->comment('litre/m/roll/nos/kg');
            $table->decimal('quantity_in_stock', 12, 2)->default(0);
            $table->decimal('reorder_level', 12, 2)->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->timestamp('last_stock_in')->nullable();
            $table->timestamp('last_stock_out')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumable_stocks');
    }
};
