<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-error alert e-mails (EN-03): a platform-level on/off switch (on by default) and an optional address.
 * With no address the alert goes to the super admins' own e-mails.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('platform_settings', 'alerts_enabled')) {
                $t->boolean('alerts_enabled')->default(true);
            }
            if (!Schema::hasColumn('platform_settings', 'alert_email')) {
                $t->string('alert_email', 120)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $t) {
            $t->dropColumn(['alerts_enabled', 'alert_email']);
        });
    }
};
