<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->morphs('attachable');
            $table->enum('type', ['qc_photo', 'stage_photo', 'delivery_proof', 'signature']);
            $table->string('file_path');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'attachable_id', 'attachable_type'], 'attachments_company_morphable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
