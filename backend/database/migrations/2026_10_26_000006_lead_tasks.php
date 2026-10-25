<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 5.2 (audit LP-01, LX-05). A lead's next actions: what to do (a call, a site visit, sending the quotation ...), when (a day and
 * optionally a time, Indian time), and who does it. `status` is open, done or cancelled (the lead was closed). A lead with open actions takes its
 * `next_follow_up_date` from the earliest one (see LeadTaskService).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('lead_tasks')) {
            Schema::create('lead_tasks', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $t->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('type', 12);
                $t->string('title', 150)->nullable();
                $t->date('due_date');
                $t->time('due_time')->nullable();
                $t->string('status', 10)->default('open');
                $t->timestamp('done_at')->nullable();
                $t->foreignId('done_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('done_note', 500)->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['company_id', 'status', 'due_date']);
                $t->index(['assigned_to_user_id', 'status', 'due_date']);
                $t->index(['lead_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_tasks');
    }
};
