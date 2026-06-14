<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — records raw material issued to a production run (what was deducted
 * from stock), plus the standard (theoretical) qty so wastage can be reported
 * (Phase 3 fills actual_qty). softDeletes required (BaseModel forces it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_material_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('run_id')->nullable()->constrained('production_runs')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('production_batches')->nullOnDelete();
            $table->enum('material_kind', ['coil', 'chemical', 'consumable']);
            $table->unsignedBigInteger('stock_id')->nullable();
            $table->string('material_name', 150);
            $table->string('unit', 20)->default('kg');
            $table->decimal('standard_qty', 12, 2)->default(0);
            $table->decimal('issued_qty', 12, 2)->default(0);
            $table->decimal('actual_qty', 12, 2)->nullable();
            $table->decimal('wastage_pct', 6, 2)->default(0);
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_material_usages');
    }
};
