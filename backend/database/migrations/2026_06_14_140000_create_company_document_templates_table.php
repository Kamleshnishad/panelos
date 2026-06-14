<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company chosen PDF template for each document type (quotation/boq/invoice).
 * One row per company+doc_type. Has company_id + deleted_at (BaseModel-safe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('doc_type', 30);
            $table->string('template_key', 50);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'doc_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_document_templates');
    }
};
