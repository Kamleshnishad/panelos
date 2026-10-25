<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-010: the monthly MIS e-mail. `email_monthly_mis` switches it on for a company (off until someone chooses it, so nothing is ever sent by surprise);
 * `mis_last_sent_month` ("2026-10") is the month it last went out for, so a re-run of the job never sends the same month twice.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('notification_settings', 'email_monthly_mis')) {
                $t->boolean('email_monthly_mis')->default(false)->after('email_payment_reminders');
                $t->string('mis_last_sent_month', 7)->nullable()->after('email_monthly_mis');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', fn (Blueprint $t) => $t->dropColumn(['email_monthly_mis', 'mis_last_sent_month']));
    }
};
