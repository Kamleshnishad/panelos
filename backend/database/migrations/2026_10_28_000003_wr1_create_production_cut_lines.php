<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WR-1: the persisted cut list. One row = one spec × one panel length × NOS
 * for a batch, generated from the order's size rows when the batch starts.
 * The floor records cut and rejected NOS per row; good NOS and completed SQM
 * are derived from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_cut_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('batch_id')->constrained('production_batches')->onDelete('cascade');
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->onDelete('set null');
            $table->foreignId('order_item_size_id')->nullable()->constrained('order_item_sizes')->onDelete('set null');
            $table->string('spec_label', 255);
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('method', 10)->default('single'); // single | double (DL)
            $table->unsignedInteger('panel_length_mm');
            $table->unsignedInteger('production_length_mm');
            $table->unsignedInteger('width_mm')->default(1000);
            $table->unsignedTinyInteger('per_strip')->default(1);
            $table->unsignedInteger('planned_nos');
            $table->unsignedInteger('cut_nos')->default(0);
            $table->unsignedInteger('rejected_nos')->default(0);
            $table->boolean('is_remake')->default(false);
            $table->timestamps();
            $table->index(['batch_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_cut_lines');
    }
};
