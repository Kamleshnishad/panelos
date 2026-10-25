<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 4.2 (audit LC-09). One row per spreadsheet import. A file is first checked (status `previewed`: nothing is created, the checked rows
 * are kept here), then imported (`done`), and the last one can be undone (`undone`). `created_lead_ids` is what undo takes back.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('lead_imports')) {
            Schema::create('lead_imports', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('file_name', 150);
                $t->string('source', 60);
                $t->string('status', 12)->default('previewed');          // previewed | running | done | failed | undone
                $t->unsignedInteger('total_rows')->default(0);
                $t->json('counts')->nullable();                           // created / merged / already_received / rejected
                $t->longText('rows')->nullable();                         // the checked rows (JSON), what the import runs from
                $t->json('created_lead_ids')->nullable();
                $t->json('undo_result')->nullable();                      // how many were taken back and how many were kept because someone had worked on them
                $t->timestamp('imported_at')->nullable();
                $t->timestamp('undone_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['company_id', 'status', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_imports');
    }
};
