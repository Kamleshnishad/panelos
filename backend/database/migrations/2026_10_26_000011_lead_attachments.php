<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 5.7 (audit LP-12). Drawings, photos, BOQ sheets and documents kept on a lead. The file itself is on the PRIVATE disk (never a public address)
 * under a random name; this row has the name the person knows it by. Served only through an authenticated download.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('lead_attachments')) {
            Schema::create('lead_attachments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $t->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('kind', 12);                            // drawing | photo | boq | document
                $t->string('original_name', 200);
                $t->string('path', 255);                           // on the private disk
                $t->string('extension', 8);
                $t->unsignedInteger('size_bytes');
                $t->timestamps();
                $t->softDeletes();
                $t->index(['lead_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_attachments');
    }
};
