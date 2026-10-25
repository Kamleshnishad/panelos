<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 5.5 (audit LP-05). A sent quotation is chased: next actions are planned for the lead 3 and 7 days after it was sent and shortly before it expires.
 * `lead_tasks.quotation_id` + `chase_step` say which quotation and which step an action came from; the pair is unique and kept even for done or removed actions,
 * so a step finished by hand is never planned again. `notification_settings.chase_quotations` is the company's switch (on by default).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_tasks', function (Blueprint $t) {
            if (!Schema::hasColumn('lead_tasks', 'quotation_id')) {
                $t->foreignId('quotation_id')->nullable()->after('lead_id')->constrained('quotations')->nullOnDelete();
                $t->string('chase_step', 10)->nullable()->after('type');                 // d3 | d7 | expiry
                $t->unique(['quotation_id', 'chase_step'], 'lead_tasks_chase_unique');
            }
        });
        Schema::table('notification_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('notification_settings', 'chase_quotations')) {
                $t->boolean('chase_quotations')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('lead_tasks', function (Blueprint $t) {
            $t->dropUnique('lead_tasks_chase_unique');
            $t->dropConstrainedForeignId('quotation_id');
            $t->dropColumn('chase_step');
        });
        Schema::table('notification_settings', fn (Blueprint $t) => $t->dropColumn('chase_quotations'));
    }
};
