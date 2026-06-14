<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail — one row per create/update/delete/restore on audited models.
 * AuditLog extends plain Model (NOT BaseModel) so it has no soft-deletes and
 * never auto-injects company_id; company_id is set explicitly by the trait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 120)->nullable();
            $table->string('action', 30);              // created / updated / deleted / restored / force_deleted
            $table->string('auditable_type', 60);      // class basename, e.g. Quotation
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('label', 120)->nullable();  // human ref e.g. SCP-2027-029
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'auditable_type', 'auditable_id']);
            $table->index(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
