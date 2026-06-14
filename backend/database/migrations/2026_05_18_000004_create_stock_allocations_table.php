<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('dispatch_id')->constrained('dispatches')->onDelete('cascade');
            $table->morphs('allocatable');
            $table->decimal('quantity_allocated', 12, 2);
            $table->enum('status', ['allocated', 'used', 'released'])->default('allocated');
            $table->timestamp('allocated_at')->useCurrent();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'dispatch_id']);
            $table->index(['allocatable_id', 'allocatable_type']);
            $table->unique(['dispatch_id', 'allocatable_id', 'allocatable_type'], 'stk_alloc_dispatch_morphable_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_allocations');
    }
};
