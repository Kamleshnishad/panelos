<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_stage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('production_batches')->onDelete('cascade');
            $table->foreignId('stage_id')->constrained('production_stages')->onDelete('restrict');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('logged_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->index(['batch_id', 'stage_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_stage_logs');
    }
};
