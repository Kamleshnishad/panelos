<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 2.4 (audit LC-10, LD-08 part). Who gets a new lead. A company starts with no rules (leads stay unassigned until someone takes them, as
 * today). A rule says "leads from <source> / in <city> / for <application> go to these people, taking turns". The first matching rule, by priority, wins;
 * a rule with no conditions is the catch-all. `last_user_id` remembers whose turn was last so the rotation carries on across leads.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('lead_assignment_rules')) {
            Schema::create('lead_assignment_rules', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->string('name', 100);
                $t->unsignedSmallInteger('priority')->default(0);
                $t->boolean('is_active')->default(true);
                $t->string('source', 60)->nullable();
                $t->string('city', 120)->nullable();
                $t->string('application', 40)->nullable();
                $t->json('user_ids');
                $t->unsignedBigInteger('last_user_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['company_id', 'priority']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_assignment_rules');
    }
};
