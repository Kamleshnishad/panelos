<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 5.6 (audit LP-10). How many days an open lead may go without anyone touching it before it counts as "quiet" and the person it belongs to is told.
 * 0 switches the bell alert off (the Quiet leads view still works, with the default of 7 days). On by default.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('notification_settings', 'lead_stale_days')) {
                $t->unsignedTinyInteger('lead_stale_days')->default(7);
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', fn (Blueprint $t) => $t->dropColumn('lead_stale_days'));
    }
};
