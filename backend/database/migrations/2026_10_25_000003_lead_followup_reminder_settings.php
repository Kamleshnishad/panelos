<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 1.7 (audit LD-06). Two company settings for lead follow-up reminders:
 *  - notify_lead_followups: bell reminders for due and overdue follow-ups (on by default; it is only the in-app bell);
 *  - lead_overdue_alert_days: the owner is told about leads overdue for more than this many days.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('notification_settings', 'notify_lead_followups')) {
                $t->boolean('notify_lead_followups')->default(true);
                $t->unsignedTinyInteger('lead_overdue_alert_days')->default(3);
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $t) {
            $t->dropColumn(['notify_lead_followups', 'lead_overdue_alert_days']);
        });
    }
};
