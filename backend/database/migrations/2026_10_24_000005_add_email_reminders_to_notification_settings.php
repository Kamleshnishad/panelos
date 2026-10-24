<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An admin switch for the automatic payment-reminder e-mails (the 09:00 reminders:send run). OFF by default: those
 * e-mails could not be sent at all until PB-049, so no company has ever opted in, and the first correct run should
 * never surprise a customer. Sending a reminder by hand from an invoice is unaffected.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('notification_settings', 'email_payment_reminders')) {
                $t->boolean('email_payment_reminders')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $t) {
            $t->dropColumn('email_payment_reminders');
        });
    }
};
