<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interaction history for a lead (calls/notes/emails + auto status-change log).
 * company_id + deleted_at present so BaseModel is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('lead_id')->constrained('leads')->onDelete('cascade');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('type', ['note', 'call', 'email', 'whatsapp', 'meeting', 'status_change'])->default('note');
            $table->text('description')->nullable();
            $table->timestamp('activity_date')->useCurrent();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
    }
};
