<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production Run = a grouping of same-spec, per-order production batches that the
 * line runs back-to-back (one setup, no changeover). Each child batch still
 * belongs to ONE order (production_batches.order_id stays single), so dispatch /
 * QC / invoice flows are untouched. The run only adds grouping + sequencing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('run_no', 50);
            $table->enum('status', ['draft', 'in_progress', 'completed', 'cancelled'])->default('draft');
            $table->string('signature', 255)->nullable();
            $table->string('label', 255)->nullable();
            $table->decimal('planned_sqm', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'run_no']);
            $table->index(['company_id', 'status']);
        });

        Schema::table('production_batches', function (Blueprint $table) {
            $table->foreignId('run_id')->nullable()->after('order_id')
                  ->constrained('production_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('run_id');
        });
        Schema::dropIfExists('production_runs');
    }
};
