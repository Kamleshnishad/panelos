<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 3.3 (audit LC-12). What the health check needs to tell "the key was refused" from "IndiaMART was unreachable"
 * (`last_error_kind`), and when the pull was switched on and the key saved (the clocks for "no good pull for an hour" and
 * IndiaMART's 7-day idle expiry).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_connectors', function (Blueprint $t) {
            if (!Schema::hasColumn('lead_connectors', 'last_error_kind')) {
                $t->string('last_error_kind', 20)->nullable()->after('last_error');
            }
            if (!Schema::hasColumn('lead_connectors', 'enabled_at')) {
                $t->timestamp('enabled_at')->nullable()->after('is_enabled');
            }
            if (!Schema::hasColumn('lead_connectors', 'key_saved_at')) {
                $t->timestamp('key_saved_at')->nullable()->after('key_hint');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lead_connectors', function (Blueprint $t) {
            $t->dropColumn(['last_error_kind', 'enabled_at', 'key_saved_at']);
        });
    }
};
