<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 5.1 (audit LD-10, LX-02). A person's own saved lead views: a name and the filters and sort of the list (status, source, assigned to,
 * follow-up, search, sort). Private to the person; the ready-made views ("My leads", "Due today", ...) are not stored, the screen defines them.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('lead_views')) {
            Schema::create('lead_views', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->string('name', 60);
                $t->json('filters');
                $t->timestamps();
                $t->softDeletes();
                $t->index(['company_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_views');
    }
};
